"""The model proposes; the runtime validates.

These tests pin the guardrails: a planner cannot invent a capability, reach
for a tool the policy forbids, or re-request a signal it already holds.
"""

from __future__ import annotations

from datetime import datetime, timezone

import pytest

from app.agent.planner import Planner
from app.llm.base import LlmClient
from app.policies import evaluator
from app.schemas import EvidenceOut, EvidencePlanOut, PolicyIn


class ScriptedLlm(LlmClient):
    provider = "scripted"
    model = "scripted-1"

    def __init__(self, response):
        self.response = response
        self.calls = 0

    async def complete_json(self, system: str, user: str):
        self.calls += 1
        return self.response


def evidence(evidence_type: str, status: str) -> EvidenceOut:
    return EvidenceOut(
        evidence_id="ev_1",
        type=evidence_type,
        status=status,
        source="CAMARA",
        received_at=datetime.now(timezone.utc),
    )


def _policy() -> PolicyIn:
    return PolicyIn(
        required_evidence=["LOCATION_VERIFICATION"],
        optional_evidence=["DEVICE_STATUS"],
        allowed_tools=["verify_location", "get_device_status"],
    )


async def _next(llm, items, allowed=None):
    policy = _policy()
    planner = Planner(llm=llm, allowed_tools=allowed or ["verify_location", "get_device_status"])
    assessment = evaluator.assess(policy, items)

    action = await planner.next_action(
        policy=policy,
        evidence=items,
        assessment=assessment,
        plan=EvidencePlanOut(minimum=["LOCATION_VERIFICATION"], escalation=["DEVICE_STATUS"]),
        budget_left=2,
        time_left_ms=10000,
    )

    return planner, action


@pytest.mark.asyncio
async def test_an_invented_tool_is_refused():
    llm = ScriptedLlm({"action": "call_tool", "tool": "drain_the_subscriber_database", "reason": "why not"})

    planner, action = await _next(llm, [evidence("LOCATION_VERIFICATION", "CONFLICTING")])

    assert action.tool != "drain_the_subscriber_database"
    assert action.source == "heuristic"
    assert planner.mode == "llm_fallback_heuristic"


@pytest.mark.asyncio
async def test_a_tool_outside_the_policy_is_refused():
    llm = ScriptedLlm({"action": "call_tool", "tool": "check_reachability", "reason": "extra signal"})

    _, action = await _next(
        llm,
        [evidence("LOCATION_VERIFICATION", "CONFLICTING")],
        allowed=["verify_location", "get_device_status"],
    )

    assert action.tool == "get_device_status"


@pytest.mark.asyncio
async def test_a_repeated_signal_is_refused():
    llm = ScriptedLlm({"action": "call_tool", "tool": "verify_location", "reason": "again"})

    _, action = await _next(llm, [evidence("LOCATION_VERIFICATION", "CONFLICTING")])

    assert action.tool != "verify_location"


@pytest.mark.asyncio
async def test_unparseable_output_degrades_to_the_deterministic_planner():
    llm = ScriptedLlm(None)

    planner, action = await _next(llm, [])

    assert action.action == "call_tool"
    assert action.tool == "verify_location"
    assert planner.mode == "llm_fallback_heuristic"


@pytest.mark.asyncio
async def test_a_valid_llm_choice_is_honoured():
    llm = ScriptedLlm({
        "action": "call_tool",
        "tool": "get_device_status",
        "reason": "Location conflicts with the claim; corroborate with device state.",
    })

    planner, action = await _next(llm, [evidence("LOCATION_VERIFICATION", "CONFLICTING")])

    assert action.tool == "get_device_status"
    assert action.source == "llm"
    assert planner.mode == "llm"
