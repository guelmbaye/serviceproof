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


# ── the assurance score, against real production runs ────────────────────


def test_a_run_that_obtained_nothing_scores_nothing():
    """The regression this test exists for.

    Freshness used to divide by every attempt, so items that never arrived
    counted as fresh — nothing that did not arrive can be stale. A live run
    where the operator answered 500 then 400 scored 15/100, all of it from
    freshness, for evidence it did not have. "How current is the evidence"
    cannot be answered "perfectly" when there is none.
    """
    p = policy()
    items = [
        evidence("LOCATION_VERIFICATION", "UNAVAILABLE"),
        evidence("DEVICE_STATUS", "UNAVAILABLE"),
    ]

    assessment = evaluator.assess(p, items)

    assert assessment.assurance_score == 0
    assert assessment.breakdown["components"]["freshness"] == 0.0
    assert assessment.breakdown["components"]["availability"] == 0.0


def test_freshness_still_measures_the_evidence_that_did_arrive():
    """Narrowing the denominator must not blind the component."""
    p = policy()
    items = [
        evidence("LOCATION_VERIFICATION", "SUPPORTED"),
        evidence("DEVICE_STATUS", "STALE"),
    ]

    assessment = evaluator.assess(p, items)

    # One of the two measurable items is stale.
    assert assessment.breakdown["components"]["freshness"] == 0.5
    # And nothing failed to arrive, so availability is untouched.
    assert assessment.breakdown["components"]["availability"] == 1.0


def test_an_unavailable_item_does_not_dilute_freshness():
    """A failed call says nothing about how current the rest is."""
    p = policy()
    with_failure = evaluator.assess(p, [
        evidence("LOCATION_VERIFICATION", "SUPPORTED"),
        evidence("DEVICE_STATUS", "UNAVAILABLE"),
    ])
    without = evaluator.assess(p, [evidence("LOCATION_VERIFICATION", "SUPPORTED")])

    assert with_failure.breakdown["components"]["freshness"] == \
        without.breakdown["components"]["freshness"] == 1.0
    # Availability is where the failure belongs, and it is felt there.
    assert with_failure.breakdown["components"]["availability"] == 0.5


def test_the_scores_from_the_recorded_demo_still_hold():
    """Pinned against the three runs on serviceproof.vylantic.com.

    These numbers appear in the submission deck, so a change to the formula
    must break this test rather than quietly contradict a slide.
    """
    standard = policy(required_evidence=["LOCATION_VERIFICATION"])
    high = policy(
        required_evidence=["LOCATION_VERIFICATION", "DEVICE_STATUS"],
        allow_partial=False,
    )

    # WO-1042 — one supporting signal, policy satisfied.
    assert evaluator.assess(standard, [
        evidence("LOCATION_VERIFICATION", "SUPPORTED"),
    ]).assurance_score == 100

    # WO-1043 — location conflicts, two others support.
    assert evaluator.assess(high, [
        evidence("LOCATION_VERIFICATION", "CONFLICTING"),
        evidence("DEVICE_STATUS", "SUPPORTED"),
        evidence("DEVICE_REACHABILITY", "SUPPORTED"),
    ]).assurance_score == 68

    # WO-1044 — the operator returned 500 then 400.
    assert evaluator.assess(standard, [
        evidence("LOCATION_VERIFICATION", "UNAVAILABLE"),
        evidence("DEVICE_STATUS", "UNAVAILABLE"),
    ]).assurance_score == 0
