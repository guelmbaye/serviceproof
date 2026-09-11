"""The evidence orchestration loop.

    receive claim
        -> load policy and build an evidence plan
        -> select the minimum sufficient tool
        -> call CAMARA through the adapter layer
        -> normalise and evaluate the evidence
        -> escalate only if another signal could change the outcome
        -> propose a decision

The interesting property is not that three APIs can be called. It is that
the second and third are called only when the first turns out to be
insufficient — and that the loop stops as soon as it has enough.
"""

from __future__ import annotations

import logging
import time

from app.agent.planner import Planner
from app.agent.trace import TraceRecorder
from app.camara import normalizer
from app.camara.normalizer import is_operator_test_device
from app.camara.client import NacClient, NacResponse
from app.camara.demo import DemoNetwork
from app.config import AGENT_VERSION, Settings
from app.llm.factory import build_llm
from app.policies import evaluator
from app.schemas import (
    AssessmentOut,
    DecisionOut,
    EvidenceOut,
    EvidencePlanOut,
    PlannerInfo,
    VerifyRequest,
    VerifyResponse,
)
from app.tools import registry
from app.tools.base import ToolContext

logger = logging.getLogger(__name__)


class EvidenceOrchestrator:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    async def run(self, payload: VerifyRequest) -> VerifyResponse:
        started = time.perf_counter()
        trace = TraceRecorder()
        evidence: list[EvidenceOut] = []

        policy = payload.policy
        budget = payload.budget
        mode = payload.demo.force_mode or self.settings.camara_mode

        allowed = self._allowed_tools(payload)
        llm = build_llm(self.settings)
        planner = Planner(llm=llm, allowed_tools=allowed)

        ctx = ToolContext(
            settings=self.settings,
            client=NacClient(self.settings),
            demo=DemoNetwork(scenario=payload.demo.scenario),
            policy=policy,
            claim=payload.claim,
            device=payload.device,
            site=payload.work_order.expected_site if payload.work_order else None,
            mode=mode,
        )

        trace.add("AGENT_STARTED", "Claim received", {
            "claim": payload.claim.reference,
            "assurance_level": budget.assurance_level,
        })
        trace.add("CONTEXT_LOADED", f"Policy {policy.key} loaded", {
            "required_evidence": policy.required_evidence,
            "evidence_budget": budget.max_tool_calls,
            "evidence_mode": mode,
        })

        # ── 0. Entitlement, before anything reaches the operator ─────────
        #
        # Knowing an MSISDN does not confer the right to query it. The binding
        # between a worker, a device and the organisation either authorises a
        # network query or it does not, and the agent enforces that rather than
        # assuming the product core already did.
        #
        # This runs before planning, not after: a refusal must cost zero API
        # calls, and the trace has to show the check happening ahead of the
        # first request rather than as a formality afterwards.
        entitlement = payload.device.entitlement if payload.device else None

        if entitlement is None or not entitlement.permits_network_query:
            status = entitlement.status if entitlement else "MISSING"
            trace.add("ENTITLEMENT_REFUSED", "Network entitlement not active", {
                "status": status,
                "device": payload.device.reference if payload.device else None,
            })

            return self._refuse(
                payload, trace, started,
                reason=(
                    f"No active network entitlement for this device (status: {status}). "
                    "Nothing was asked of the operator."
                ),
            )

        trace.add("ENTITLEMENT_VERIFIED", "Network entitlement verified", {
            "status": entitlement.status,
            "reference": entitlement.reference,
            "device": payload.device.reference if payload.device else None,
        })

        # ── 1. Plan before calling anything ──────────────────────────────
        try:
            plan = await planner.build_plan(self._plan_context(payload, allowed))
        except Exception as exc:  # noqa: BLE001 - planning must never crash a run
            logger.exception("Evidence planning failed; using the deterministic plan.")
            plan = EvidencePlanOut(
                minimum=policy.required_evidence,
                escalation=policy.optional_evidence,
                rationale=f"Deterministic plan used after a planner error: {exc}",
            )

        trace.add("PLAN_CREATED", "Evidence plan created", {
            "minimum": plan.minimum,
            "escalation": plan.escalation,
            "rationale": plan.rationale,
            "planner": planner.mode,
        })

        assessment = evaluator.assess(policy, evidence)
        escalated = False
        calls = 0
        deadline_ms = budget.max_latency_ms or 12000

        # ── 2. Collect the minimum sufficient evidence ───────────────────
        while calls < budget.max_tool_calls:
            elapsed_ms = int((time.perf_counter() - started) * 1000)
            time_left = deadline_ms - elapsed_ms

            if time_left <= 0:
                trace.add("BUDGET_EXHAUSTED", "Latency budget reached", {"elapsed_ms": elapsed_ms})
                break

            decision_step = await planner.next_action(
                policy=policy,
                evidence=evidence,
                assessment=assessment,
                plan=plan,
                budget_left=budget.max_tool_calls - calls,
                time_left_ms=time_left,
            )

            if decision_step.action == "stop":
                trace.add("POLICY_EVALUATED", decision_step.reason, {
                    "decided_by": decision_step.source,
                    "tool_calls_used": calls,
                })
                break

            tool = registry.get_tool(decision_step.tool or "")

            if tool is None:
                trace.add("ESCALATION_REQUIRED", "No permitted tool available for the next step")
                break

            if calls > 0:
                escalated = True
                trace.add("ESCALATION_REQUIRED", decision_step.reason, {
                    "next_tool": tool.name,
                    "decided_by": decision_step.source,
                })

            trace.add("TOOL_SELECTED", f"{tool.api_name} selected", {
                "tool": tool.name,
                "reason": decision_step.reason,
                "decided_by": decision_step.source,
                "business_question": tool.business_question,
            })

            trace.add("TOOL_CALLED", f"{tool.api_name} requested via Nokia Network as Code", {
                "tool": tool.name,
            })

            try:
                item = await tool.run(ctx)
            except Exception as exc:  # noqa: BLE001 - a tool failure is evidence-unavailable
                # A crash in our own code is still an absence of evidence, and
                # it has to leave a record. Dropping through with nothing
                # appended produced runs that decided UNVERIFIED with an empty
                # evidence table — correct verdict, no way to see why. The item
                # below is what a reviewer opens to find the reason.
                logger.exception("Tool %s raised", tool.name)

                item = normalizer.unavailable(
                    tool.evidence_type,
                    NacResponse(
                        ok=False,
                        api=tool.api_name,
                        request_id="n/a",
                        latency_ms=0,
                        error=f"{type(exc).__name__}: {exc}",
                        error_kind="INTERNAL",
                    ),
                    "CAMARA",
                )

            calls += 1

            # Record which of the three source modes produced this item, in
            # the item itself. A real HTTPS call to the operator about one of
            # ITU's reserved +999 test numbers is not the same thing as a call
            # about a subscriber, and presenting both as "live network
            # evidence" claims more than we did.
            item.normalized = {
                **item.normalized,
                "operator_test_device": is_operator_test_device(payload.device.identifier),
            }

            evidence.append(item)

            label = {
                "SUPPORTED": f"{tool.api_name}: supports the claim",
                "CONFLICTING": f"{tool.api_name}: conflicts with the claim",
                "UNAVAILABLE": f"{tool.api_name}: evidence unavailable",
                "STALE": f"{tool.api_name}: evidence too old to rely on",
                "INVALID": f"{tool.api_name}: unusable response",
            }[item.status]

            event_type = {
                "CONFLICTING": "EVIDENCE_CONFLICT",
                "UNAVAILABLE": "EVIDENCE_UNAVAILABLE",
            }.get(item.status, "EVIDENCE_RECEIVED")

            trace.add(event_type, label, {
                "type": item.type,
                "status": item.status,
                "source": item.source,
                "request_id": item.request_id,
                "summary": item.summary,
                "latency_ms": item.latency_ms,
            })

            assessment = evaluator.assess(policy, evidence)

            if assessment.sufficient:
                trace.add("POLICY_EVALUATED", "Minimum sufficient evidence reached", {
                    "assurance_score": assessment.assurance_score,
                })
                break

        else:
            if calls >= budget.max_tool_calls:
                trace.add("BUDGET_EXHAUSTED", "Evidence budget reached", {
                    "tool_calls_used": calls,
                    "max_tool_calls": budget.max_tool_calls,
                })

        # ── 3. Deterministic evaluation and proposal ─────────────────────
        assessment = evaluator.assess(policy, evidence)
        decision = evaluator.decide(policy, evidence, assessment)

        trace.add("POLICY_EVALUATED", f"Policy {policy.key} evaluated", {
            "satisfied": decision.policy_satisfied,
            "missing_required": assessment.missing_required,
            "supported": assessment.supported,
            "conflicting": assessment.conflicting,
            "unavailable": assessment.unavailable,
        })

        trace.add("DECISION_PROPOSED", f"Decision: {decision.state}", {
            "recommended_action": decision.recommended_action,
            "assurance_score": assessment.assurance_score,
            "rationale": decision.rationale,
        })

        duration_ms = int((time.perf_counter() - started) * 1000)

        trace.add("AGENT_COMPLETED", "Verification completed", {
            "tool_calls_used": calls,
            "duration_ms": duration_ms,
            "escalated": escalated,
        })

        return VerifyResponse(
            status="COMPLETED",
            agent_version=AGENT_VERSION,
            planner=PlannerInfo(
                mode=planner.mode,  # type: ignore[arg-type]
                provider=llm.provider,
                model=getattr(llm, "model", None),
            ),
            evidence_plan=plan,
            evidence=evidence,
            trace=trace.events,
            assessment=assessment,
            decision=decision,
            tool_calls_used=calls,
            stop_reason=self._stop_reason(assessment, calls, budget.max_tool_calls),
            duration_ms=duration_ms,
            escalated=escalated,
            used_demo_fallback=ctx.used_demo_fallback,
        )

    def _refuse(
        self,
        payload: VerifyRequest,
        trace: TraceRecorder,
        started: float,
        *,
        reason: str,
    ) -> VerifyResponse:
        """End the run without asking the operator anything.

        UNVERIFIED, not DISPUTED. A missing entitlement says nothing about
        whether the technician did the work — it says we were not permitted to
        look. Turning an authorisation gap into evidence against a person is
        precisely the failure mode this product is built to avoid.
        """
        assessment = AssessmentOut(
            sufficient=False,
            conflicting=False,
            missing_required=list(payload.policy.required_evidence),
            assurance_score=0,
            breakdown={
                "components": {
                    "completeness": 0.0,
                    "consistency": 0.0,
                    "freshness": 0.0,
                    "availability": 0.0,
                },
                "counts": {"total": 0, "measurable": 0},
                "provenance": "NO_EVIDENCE_GATHERED",
            },
            rationale=reason,
        )

        decision = DecisionOut(
            state="UNVERIFIED",
            recommended_action="MANUAL_VERIFICATION",
            rationale=reason,
            policy_satisfied=False,
        )

        trace.add("AGENT_COMPLETED", "Verification ended before any network call", {
            "state": decision.state,
            "tool_calls_used": 0,
        })

        return VerifyResponse(
            status="COMPLETED",
            agent_version=AGENT_VERSION,
            # No planner ran, so the deterministic mode is the honest label:
            # nothing was inferred, the gate simply refused.
            planner=PlannerInfo(mode="heuristic", provider=None, model=None),
            evidence_plan=EvidencePlanOut(
                minimum=list(payload.policy.required_evidence),
                escalation=[],
                rationale="No plan was built: the run stopped at the entitlement check.",
            ),
            evidence=[],
            trace=trace.events,
            assessment=assessment,
            decision=decision,
            tool_calls_used=0,
            duration_ms=int((time.perf_counter() - started) * 1000),
            escalated=False,
            used_demo_fallback=False,
            stop_reason="ENTITLEMENT_REFUSED",
        )

    # ── helpers ──────────────────────────────────────────────────────────

    @staticmethod
    def _stop_reason(assessment, calls: int, ceiling: int) -> str:
        """Why the loop stopped, derived from what happened.

        Order matters. A run that hit the ceiling *and* had a standing conflict
        stopped because of the conflict — the ceiling is incidental. Reporting
        BUDGET_EXHAUSTED there would describe the arithmetic rather than the
        reasoning, which is exactly the impression this field exists to correct.
        """
        if assessment.sufficient:
            return "EVIDENCE_SUFFICIENT"

        if assessment.conflicting:
            return "MATERIAL_CONFLICT_CONFIRMED"

        if calls >= ceiling:
            return "BUDGET_EXHAUSTED"

        return "NO_CAPABILITY_AVAILABLE"

    def _allowed_tools(self, payload: VerifyRequest) -> list[str]:
        """Intersection of what the deployment enables, what the policy
        permits, what the entitlement covers, and what actually exists.
        Nothing else is ever callable.

        The entitlement is the narrowest of the four and the only one that
        represents a permission rather than a configuration. An entitlement to
        ask where a device is does not extend to asking whether its SIM
        changed, and the allow-list is where that distinction is enforced —
        before the planner ever sees the tool, so a model cannot request it.
        """
        requested = payload.tools or payload.policy.allowed_tools
        available = registry.available_tools(requested or None)

        entitlement = payload.device.entitlement if payload.device else None

        if entitlement is None or not entitlement.allowed_capabilities:
            return list(available.keys())

        return [
            name for name, tool in available.items()
            if entitlement.permits_capability(tool.evidence_type)
        ]

    def _plan_context(self, payload: VerifyRequest, allowed: list[str]) -> dict:
        return {
            "claim": {
                "reference": payload.claim.reference,
                "type": payload.claim.type,
                "claimed_at": payload.claim.claimed_at,
            },
            "work_order": (
                {
                    "reference": payload.work_order.reference,
                    "service_type": payload.work_order.service_type,
                    "risk_level": payload.work_order.risk_level,
                    "expected_site": payload.work_order.expected_site.model_dump(mode="json"),
                    "window": payload.work_order.window.model_dump(mode="json"),
                }
                if payload.work_order
                else {}
            ),
            "policy": payload.policy.model_dump(mode="json"),
            "policy_model": payload.policy,
            "tools": registry.specs(allowed),
            "budget": payload.budget.model_dump(mode="json"),
            "untrusted_notes": payload.claim.untrusted_notes,
        }
