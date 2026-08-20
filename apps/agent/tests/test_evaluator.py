from __future__ import annotations

from datetime import datetime, timezone

from app.policies import evaluator
from app.schemas import EvidenceOut, PolicyIn


def evidence(evidence_type: str, status: str, source: str = "CAMARA") -> EvidenceOut:
    return EvidenceOut(
        evidence_id="ev_1",
        type=evidence_type,
        status=status,
        source=source,
        received_at=datetime.now(timezone.utc),
    )


def policy(**overrides) -> PolicyIn:
    return PolicyIn(**{"required_evidence": ["LOCATION_VERIFICATION"], **overrides})


def test_supported_required_evidence_is_sufficient():
    p = policy()
    items = [evidence("LOCATION_VERIFICATION", "SUPPORTED")]
    assessment = evaluator.assess(p, items)

    assert assessment.sufficient
    assert evaluator.decide(p, items, assessment).state == "VERIFIED"


def test_a_conflict_blocks_verification_even_with_other_support():
    p = policy()
    items = [
        evidence("LOCATION_VERIFICATION", "CONFLICTING"),
        evidence("DEVICE_STATUS", "SUPPORTED"),
    ]
    assessment = evaluator.assess(p, items)

    assert not assessment.sufficient
    assert evaluator.decide(p, items, assessment).state == "DISPUTED"


def test_unavailable_is_not_negative_evidence():
    p = policy()
    items = [evidence("LOCATION_VERIFICATION", "UNAVAILABLE")]
    assessment = evaluator.assess(p, items)
    decision = evaluator.decide(p, items, assessment)

    assert decision.state == "UNVERIFIED"
    assert "absence of evidence" in (decision.rationale or "")


def test_partial_assurance_only_when_the_policy_allows_it():
    strict = policy(required_evidence=["LOCATION_VERIFICATION", "DEVICE_STATUS"], allow_partial=False)
    lenient = policy(required_evidence=["LOCATION_VERIFICATION", "DEVICE_STATUS"], allow_partial=True)
    items = [evidence("LOCATION_VERIFICATION", "SUPPORTED")]

    assert evaluator.decide(strict, items, evaluator.assess(strict, items)).state == "UNVERIFIED"
    assert evaluator.decide(lenient, items, evaluator.assess(lenient, items)).state == "PARTIAL"


def test_escalation_is_refused_once_evidence_is_sufficient():
    p = policy()
    items = [evidence("LOCATION_VERIFICATION", "SUPPORTED")]
    assessment = evaluator.assess(p, items)

    escalate, reason = evaluator.should_escalate(p, items, assessment, budget_left=2)

    assert escalate is False
    assert "Minimum sufficient evidence" in reason


def test_escalation_is_requested_when_a_signal_conflicts():
    p = policy()
    items = [evidence("LOCATION_VERIFICATION", "CONFLICTING")]
    assessment = evaluator.assess(p, items)

    escalate, _ = evaluator.should_escalate(p, items, assessment, budget_left=2)

    assert escalate is True


def test_escalation_stops_when_the_budget_is_gone():
    p = policy()
    items = [evidence("LOCATION_VERIFICATION", "CONFLICTING")]
    assessment = evaluator.assess(p, items)

    escalate, reason = evaluator.should_escalate(p, items, assessment, budget_left=0)

    assert escalate is False
    assert "budget" in reason.lower()


def test_the_assurance_score_is_explainable():
    p = policy()
    items = [evidence("LOCATION_VERIFICATION", "SUPPORTED")]
    assessment = evaluator.assess(p, items)

    assert 0 <= assessment.assurance_score <= 100
    assert set(assessment.breakdown["components"]) == {
        "completeness", "consistency", "freshness", "availability"
    }
    assert assessment.breakdown["provenance"] == "LIVE_NETWORK_EVIDENCE"
