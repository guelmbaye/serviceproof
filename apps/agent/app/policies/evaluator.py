"""Deterministic evidence evaluation.

This module contains no AI. It answers one question — "is what we have
sufficient under this policy?" — using explicit rules, so the outcome can
be shown to a reviewer line by line.

The same rules are re-applied by Laravel's DecisionGuard on the way back.
Duplication is deliberate: the agent proposes, the product core disposes.
"""

from __future__ import annotations

from app.schemas import AssessmentOut, DecisionOut, EvidenceOut, PolicyIn

USABLE = {"SUPPORTED", "CONFLICTING"}

ACTION_FOR_STATE = {
    "VERIFIED": "CLOSE",
    "PARTIAL": "REVIEW",
    "DISPUTED": "ESCALATE",
    "UNVERIFIED": "MANUAL_VERIFICATION",
}


def assess(policy: PolicyIn, evidence: list[EvidenceOut]) -> AssessmentOut:
    supported = [e for e in evidence if e.status == "SUPPORTED"]
    conflicting = [e for e in evidence if e.status == "CONFLICTING"]
    unavailable = [e for e in evidence if e.status == "UNAVAILABLE"]
    stale = [e for e in evidence if e.status == "STALE"]
    usable = [e for e in evidence if e.status in USABLE]

    satisfied_types = {e.type for e in supported}
    missing_required = [t for t in policy.required_evidence if t not in satisfied_types]

    sufficient = bool(usable) and not conflicting and not missing_required

    breakdown = _breakdown(
        total=len(evidence),
        usable=len(usable),
        supported=len(supported),
        conflicting=len(conflicting),
        unavailable=len(unavailable),
        stale=len(stale),
        required_total=len(policy.required_evidence),
        required_satisfied=len(policy.required_evidence) - len(missing_required),
        simulated=any(e.source == "DEMO_FALLBACK" for e in evidence),
    )

    return AssessmentOut(
        sufficient=sufficient,
        supported=len(supported),
        conflicting=len(conflicting),
        unavailable=len(unavailable),
        stale=len(stale),
        missing_required=missing_required,
        assurance_score=breakdown["score"],
        breakdown=breakdown,
    )


def decide(policy: PolicyIn, evidence: list[EvidenceOut], assessment: AssessmentOut) -> DecisionOut:
    usable = [e for e in evidence if e.status in USABLE]

    if not usable:
        state = "UNVERIFIED"
        rationale = (
            "No usable network evidence was returned. An unavailable API is an absence of "
            "evidence, not evidence against the claim."
        )
    elif assessment.conflicting:
        state = "DISPUTED"
        rationale = (
            "Network evidence materially conflicts with automatic assurance "
            f"requirements ({assessment.conflicting} conflicting signal(s))."
        )
    elif not assessment.missing_required:
        state = "VERIFIED"
        rationale = "Available network evidence sufficiently supports this claim under policy."
    elif policy.allow_partial and assessment.supported:
        state = "PARTIAL"
        rationale = (
            "Supporting evidence exists but policy requirements are incomplete: "
            + ", ".join(assessment.missing_required)
            + "."
        )
    else:
        state = "UNVERIFIED"
        rationale = (
            "Required evidence is missing and this policy does not allow partial assurance: "
            + ", ".join(assessment.missing_required)
            + "."
        )

    return DecisionOut(
        state=state,  # type: ignore[arg-type]
        recommended_action=ACTION_FOR_STATE[state],
        policy_satisfied=state == "VERIFIED",
        rationale=rationale,
    )


def should_escalate(
    policy: PolicyIn, evidence: list[EvidenceOut], assessment: AssessmentOut, budget_left: int
) -> tuple[bool, str]:
    """Is another signal actually worth spending budget on?

    This is the heart of the evidence budget: more API calls do not make a
    better decision. We only escalate when a further signal could change the
    outcome or complete a policy requirement.
    """
    if budget_left <= 0:
        return False, "Evidence budget exhausted."

    if assessment.sufficient:
        return False, "Minimum sufficient evidence already collected."

    if assessment.conflicting:
        # One corroborating call, not every remaining tool.
        #
        # A conflicting primary signal blocks automatic verification whatever
        # else agrees, so a second corroborating signal is worth one call — it
        # distinguishes "the device was elsewhere" from "the network could not
        # see the device at all" — and a third is worth none. Spending the rest
        # of the budget on signals that cannot change the outcome is the
        # opposite of what an evidence budget is for.
        corroborating = [
            item for item in evidence
            if item.type not in policy.required_evidence and item.status != "UNAVAILABLE"
        ]

        if corroborating:
            return False, (
                "A signal conflicts with the claim and corroborating evidence did not "
                "reconcile it. No remaining capability can satisfy the requirement, so "
                "further calls would not change the decision."
            )

        return True, "A signal conflicts with the claim; corroborating evidence is needed to reconcile it."

    if assessment.missing_required:
        return True, (
            "Policy still requires: " + ", ".join(assessment.missing_required) + "."
        )

    if assessment.unavailable and not assessment.supported:
        return True, "The primary signal was unavailable; an alternative signal may still be obtainable."

    return False, "No further signal would change the outcome."


def _breakdown(
    total: int,
    usable: int,
    supported: int,
    conflicting: int,
    unavailable: int,
    stale: int,
    required_total: int,
    required_satisfied: int,
    simulated: bool,
) -> dict:
    completeness = (required_satisfied / required_total) if required_total else (1.0 if supported else 0.0)
    consistency = ((usable - conflicting) / usable) if usable else 0.0

    # Freshness is measured over the evidence that exists, not over the
    # attempts. Dividing by `total` counted UNAVAILABLE items as fresh, since
    # nothing that never arrived can be stale — so a run where the operator
    # answered 500 then 400 scored a perfect 100 on freshness and collected
    # 15 points for evidence it did not have. "How current is the evidence"
    # cannot be answered "perfectly" when the answer is "there is none".
    measurable = usable + stale
    freshness = ((measurable - stale) / measurable) if measurable else 0.0

    # Availability keeps its full denominator: it is precisely the ratio of
    # attempts that came back, so the ones that did not are the point.
    availability = ((total - unavailable) / total) if total else 0.0

    score = round(
        100 * (0.40 * completeness + 0.35 * consistency + 0.15 * freshness + 0.10 * availability)
    )

    return {
        "score": max(0, min(100, int(score))),
        "components": {
            "completeness": round(completeness, 3),
            "consistency": round(consistency, 3),
            "freshness": round(freshness, 3),
            "availability": round(availability, 3),
        },
        "weights": {"completeness": 0.40, "consistency": 0.35, "freshness": 0.15, "availability": 0.10},
        "counts": {
            "measurable": measurable,
            "total": total,
            "usable": usable,
            "supported": supported,
            "conflicting": conflicting,
            "unavailable": unavailable,
            "stale": stale,
            "required_total": required_total,
            "required_satisfied": required_satisfied,
        },
        "provenance": "INCLUDES_SIMULATED_EVIDENCE" if simulated else "LIVE_NETWORK_EVIDENCE",
    }
