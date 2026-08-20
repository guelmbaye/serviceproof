"""The agentic layer: what evidence do we need, and is one more signal worth it?

Two planners, one interface:

  * LLM planner — interprets the claim, the policy and the evidence gathered
    so far, and chooses the next tool.
  * Deterministic planner — the same decision expressed as explicit rules.

Every LLM output is validated against the allowed tool list and the remaining
budget before anything executes. If the model is unreachable, returns junk, or
names a tool it is not permitted to use, the deterministic planner takes over
and the response reports mode="llm_fallback_heuristic". The demo never depends
on an external model being up.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass

from app.agent.prompts import SYSTEM_PROMPT, next_action_prompt, plan_prompt
from app.llm.base import LlmClient
from app.policies import evaluator
from app.schemas import AssessmentOut, EvidencePlanOut, EvidenceOut, PolicyIn
from app.tools import registry

logger = logging.getLogger(__name__)


@dataclass
class NextAction:
    action: str  # "call_tool" | "stop"
    tool: str | None
    reason: str
    source: str  # "llm" | "heuristic"


class Planner:
    def __init__(self, llm: LlmClient, allowed_tools: list[str]) -> None:
        self.llm = llm
        self.allowed_tools = allowed_tools
        self.used_llm = False
        self.fell_back = False

    @property
    def mode(self) -> str:
        if not self.used_llm:
            return "heuristic"
        return "llm_fallback_heuristic" if self.fell_back else "llm"

    # ── evidence plan ────────────────────────────────────────────────────

    async def build_plan(self, context: dict) -> EvidencePlanOut:
        heuristic = self._heuristic_plan(context["policy_model"])

        if isinstance(self.llm.provider, str) and self.llm.provider == "heuristic":
            return heuristic

        raw = await self.llm.complete_json(
            system=SYSTEM_PROMPT,
            user=plan_prompt(context),
        )
        self.used_llm = True

        if not raw:
            self.fell_back = True
            return heuristic

        minimum = self._valid_types(raw.get("minimum"), context["policy_model"])
        escalation = self._valid_types(raw.get("escalation"), context["policy_model"])

        if not minimum:
            self.fell_back = True
            return heuristic

        return EvidencePlanOut(
            minimum=minimum,
            escalation=[t for t in escalation if t not in minimum],
            rationale=str(raw.get("rationale") or heuristic.rationale)[:500],
        )

    # ── next step ────────────────────────────────────────────────────────

    async def next_action(
        self,
        policy: PolicyIn,
        evidence: list[EvidenceOut],
        assessment: AssessmentOut,
        plan: EvidencePlanOut,
        budget_left: int,
        time_left_ms: int,
    ) -> NextAction:
        fallback = self._heuristic_action(policy, evidence, assessment, plan, budget_left)

        if self.llm.provider == "heuristic" or budget_left <= 0 or time_left_ms <= 1500:
            return fallback

        context = {
            "policy": policy.model_dump(mode="json"),
            "tools": [
                spec | {"already_collected": spec["evidence_type"] in {e.type for e in evidence}}
                for spec in registry.specs(self.allowed_tools)
            ],
            "evidence": [
                {
                    "type": e.type,
                    "status": e.status,
                    "summary": e.summary,
                    "source": e.source,
                    "freshness": e.freshness,
                }
                for e in evidence
            ],
            "assessment": assessment.model_dump(mode="json"),
            "budget_left": budget_left,
            "time_left_ms": time_left_ms,
        }

        raw = await self.llm.complete_json(
            system=SYSTEM_PROMPT,
            user=next_action_prompt(context),
        )
        self.used_llm = True

        if not raw:
            self.fell_back = True
            return fallback

        action = str(raw.get("action", "")).strip().lower()
        tool = raw.get("tool")
        reason = str(raw.get("reason") or "")[:300]

        if action == "stop":
            return NextAction("stop", None, reason or "Evidence considered sufficient.", "llm")

        if action != "call_tool":
            self.fell_back = True
            return fallback

        # Hard validation: the model cannot invent or borrow a capability.
        if not isinstance(tool, str) or tool not in self.allowed_tools or registry.get_tool(tool) is None:
            logger.warning("Planner requested an unavailable tool %r; using the deterministic planner.", tool)
            self.fell_back = True
            return fallback

        # Nor repeat a signal it already has.
        if registry.get_tool(tool).evidence_type in {e.type for e in evidence}:
            self.fell_back = True
            return fallback

        return NextAction("call_tool", tool, reason or f"Additional evidence required: {tool}.", "llm")

    # ── deterministic core ───────────────────────────────────────────────

    def _heuristic_plan(self, policy: PolicyIn) -> EvidencePlanOut:
        minimum = [t for t in policy.required_evidence if self._tool_for(t)]
        escalation = [t for t in policy.optional_evidence if self._tool_for(t) and t not in minimum]

        return EvidencePlanOut(
            minimum=minimum or ["LOCATION_VERIFICATION"],
            escalation=escalation,
            rationale=(
                f"Policy {policy.key} is satisfied by {', '.join(minimum) or 'location evidence'} alone; "
                f"escalate to {', '.join(escalation) or 'no further signal'} only if that proves "
                "insufficient or contested."
            ),
        )

    def _heuristic_action(
        self,
        policy: PolicyIn,
        evidence: list[EvidenceOut],
        assessment: AssessmentOut,
        plan: EvidencePlanOut,
        budget_left: int,
    ) -> NextAction:
        collected = {e.type for e in evidence}

        # 1. Nothing yet: start with the cheapest sufficient signal.
        if not evidence:
            for evidence_type in plan.minimum:
                tool = self._tool_for(evidence_type)
                if tool:
                    return NextAction(
                        "call_tool", tool.name,
                        f"Policy {policy.key} requires {evidence_type} as the primary signal.",
                        "heuristic",
                    )

        escalate, reason = evaluator.should_escalate(policy, evidence, assessment, budget_left)

        if not escalate:
            return NextAction("stop", None, reason, "heuristic")

        # 2. Complete an unmet policy requirement first.
        for evidence_type in assessment.missing_required:
            if evidence_type in collected:
                continue
            tool = self._tool_for(evidence_type)
            if tool:
                return NextAction("call_tool", tool.name, reason, "heuristic")

        # 3. Then reach for the escalation ladder, in order.
        for evidence_type in list(plan.escalation) + list(policy.optional_evidence):
            if evidence_type in collected:
                continue
            tool = self._tool_for(evidence_type)
            if tool:
                return NextAction("call_tool", tool.name, reason, "heuristic")

        return NextAction("stop", None, "No further permitted signal is available.", "heuristic")

    def _tool_for(self, evidence_type: str):
        tool = registry.tool_for_evidence_type(evidence_type)
        if tool and tool.name in self.allowed_tools:
            return tool
        return None

    def _valid_types(self, values, policy: PolicyIn) -> list[str]:
        if not isinstance(values, list):
            return []

        permitted = set(policy.required_evidence) | set(policy.optional_evidence)

        return [
            value
            for value in values
            if isinstance(value, str) and value in permitted and self._tool_for(value)
        ]
