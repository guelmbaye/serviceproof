"""CAMARA response -> normalised ServiceProof evidence.

One rule governs this module: an absent signal is never a negative signal.
An API timeout produces UNAVAILABLE, never CONFLICTING, because a failed
call says nothing at all about whether the service happened.
"""

from __future__ import annotations

import hashlib
import json
import logging
import uuid
from datetime import datetime, timezone
from typing import Any

from app.camara.client import NacResponse
from app.schemas import EvidenceOut

logger = logging.getLogger(__name__)

PROVIDER = "NOKIA_NAC"

# Two machines disagreeing by a few minutes is normal. Beyond this, an
# observation dated in the future means the timestamp was misread, not that
# the clocks drifted.
CLOCK_SKEW_TOLERANCE_SECONDS = 300


def _now() -> datetime:
    return datetime.now(timezone.utc)


def _as_utc(value: datetime | None) -> datetime | None:
    """Force a datetime onto the UTC timeline.

    Every timestamp inside ServiceProof is timezone-aware, because the moment
    a naive one slips in, the first subtraction against `now()` raises and the
    evidence is lost. Anything naive that reaches here is treated as UTC:
    CAMARA specifies UTC for these fields, and guessing the server's local zone
    would be worse than assuming the documented one.
    """
    if value is None:
        return None

    return value.replace(tzinfo=timezone.utc) if value.tzinfo is None else value.astimezone(
        timezone.utc
    )


def _parse_dt(value: Any) -> datetime | None:
    """Parse an operator timestamp into an aware UTC datetime.

    Nokia returns lastLocationTime without an offset, so fromisoformat yields a
    naive value; _as_utc is what stops that from poisoning the arithmetic
    downstream. The parser is deliberately forgiving about separators and
    trailing zone markers, because this is somebody else's serialiser and it
    only has to be read, not round-tripped.
    """
    if not value or not isinstance(value, str):
        return None

    candidate = value.strip()

    if candidate.endswith(("Z", "z")):
        candidate = candidate[:-1] + "+00:00"

    # Some serialisers use a space instead of the ISO 8601 'T'.
    if len(candidate) > 10 and candidate[10] == " ":
        candidate = candidate[:10] + "T" + candidate[11:]

    try:
        return _as_utc(datetime.fromisoformat(candidate))
    except ValueError:
        logger.warning("Unparseable timestamp from the network API: %r", value)
        return None


def _hash(payload: dict[str, Any]) -> str:
    blob = json.dumps(payload, sort_keys=True, default=str).encode()
    return "sha256:" + hashlib.sha256(blob).hexdigest()[:32]


def _base(
    evidence_type: str,
    response: NacResponse,
    source: str,
    status: str,
    summary: str,
    normalized: dict[str, Any],
    observed_at: datetime | None,
    reliability: float | None,
    freshness_seconds: int,
) -> EvidenceOut:
    received = _now()
    observed_at = _as_utc(observed_at)
    age = int((received - observed_at).total_seconds()) if observed_at else None

    # A negative age means the network says it observed the device in our
    # future. Small values are ordinary clock skew between two machines and
    # round to nothing. A large one means the assumption in _as_utc was wrong
    # for this operator — the timestamp was not UTC — and every age derived
    # from it is fiction.
    #
    # In that case the honest move is to stop claiming to know the age, not to
    # guess an offset. Freshness becomes UNKNOWN and the staleness check below
    # is skipped, so a good observation is never downgraded on the strength of
    # a timestamp we cannot read.
    if age is not None and age < -CLOCK_SKEW_TOLERANCE_SECONDS:
        logger.warning(
            "Observation timestamp is %ss in the future; treating its age as unknown. "
            "The provider may not be sending UTC.",
            abs(age),
        )
        age = None
        observed_at = None
    elif age is not None and age < 0:
        age = 0

    if status in {"SUPPORTED", "CONFLICTING"} and age is not None and age > freshness_seconds:
        status = "STALE"
        summary = f"{summary} Observation is older than the policy freshness window."

    freshness = "STALE" if status == "STALE" else ("CURRENT" if age is not None else "UNKNOWN")

    return EvidenceOut(
        evidence_id=f"ev_{uuid.uuid4().hex[:12]}",
        type=evidence_type,  # type: ignore[arg-type]
        status=status,  # type: ignore[arg-type]
        source=source,  # type: ignore[arg-type]
        provider=PROVIDER if source == "CAMARA" else "SIMULATED",
        api=response.api,
        request_id=response.request_id,
        observed_at=observed_at,
        received_at=received,
        freshness=freshness,  # type: ignore[arg-type]
        age_seconds=age,
        latency_ms=response.latency_ms,
        reliability=reliability,
        summary=(
            f"{summary} Substituted after the live call failed."
            if response.fallback_from
            else summary
        ),
        normalized=(
            {**normalized, "fell_back_from": response.fallback_from}
            if response.fallback_from
            else normalized
        ),
        payload_hash=_hash(response.payload) if response.payload else None,
        # Data minimisation: the raw provider payload is not shipped upward.
        # Only the shape needed to defend the decision travels with it.
        raw_reference={"status_code": response.status_code} if response.status_code else None,
    )


# What each failure actually means, for the person who has to do something
# about it. "Temporarily unavailable" is true of a timeout and false of a 400.
_FAILURE_SUMMARY = {
    "UNSUPPORTED": (
        "The network cannot answer for this device. That is a provisioning "
        "matter rather than an outage, and it will not resolve by retrying."
    ),
    "AUTH": (
        "The network refused our credentials, so no evidence could be gathered."
    ),
    "RATE_LIMIT": (
        "The network is rate limiting us. Evidence is obtainable, just not right now."
    ),
    "INTERNAL": (
        "ServiceProof failed to process the network response. The fault is ours, "
        "not the operator's, and the claim is unaffected."
    ),
}

_DEFAULT_FAILURE_SUMMARY = "Network evidence temporarily unavailable."

_NOT_AGAINST_THE_CLAIM = (
    " This is an absence of evidence, not evidence against the claim."
)


def unavailable(evidence_type: str, response: NacResponse, source: str) -> EvidenceOut:
    """A failed call. Explicitly not negative evidence."""
    kind = response.error_kind or ""
    summary = _FAILURE_SUMMARY.get(kind, _DEFAULT_FAILURE_SUMMARY) + _NOT_AGAINST_THE_CLAIM

    # When the simulated adapter answered because a live call failed, the live
    # failure is the one that explains the run.
    reason = response.error

    if response.fallback_from:
        summary = (
            "The live network call failed and the simulated fallback could not "
            "supply this signal either." + _NOT_AGAINST_THE_CLAIM
        )
        live = (response.fallback_from or "").rstrip(". ")
        fallback_reason = (response.error or "no reason given").rstrip(". ")
        reason = f"Live call failed — {live}. Simulated fallback — {fallback_reason}."

    return EvidenceOut(
        evidence_id=f"ev_{uuid.uuid4().hex[:12]}",
        type=evidence_type,  # type: ignore[arg-type]
        status="UNAVAILABLE",
        source=source,  # type: ignore[arg-type]
        provider=PROVIDER if source == "CAMARA" else "SIMULATED",
        api=response.api,
        request_id=response.request_id,
        received_at=_now(),
        freshness="UNKNOWN",
        latency_ms=response.latency_ms,
        summary=summary,
        normalized={
            "error_kind": response.error_kind,
            **({"fell_back_from": response.fallback_from} if response.fallback_from else {}),
        },
        failure_reason=reason,
    )


def _channels(value: object) -> list[str]:
    """Normalise Nokia's `connectivity` field to a list of channel names.

    It arrives as a list — ["SMS"], sometimes ["DATA", "SMS"] — and coercing
    that with str() produced summaries reading "reachable over ['sms']". A
    single string is accepted too, because one operator's array is another's
    scalar and neither should reach a reviewer as Python syntax.
    """
    if isinstance(value, (list, tuple, set)):
        return [str(item).upper() for item in value if item]
    if value:
        return [str(value).upper()]
    return []


def _phrase(channels: list[str]) -> str:
    """["DATA", "SMS"] -> "data and sms"."""
    names = [channel.lower() for channel in channels]

    if not names:
        return ""
    if len(names) == 1:
        return names[0]

    return ", ".join(names[:-1]) + " and " + names[-1]


def unreadable(evidence_type: str, response: NacResponse, source: str, detail: str) -> EvidenceOut:
    """The call succeeded and the answer means nothing to us.

    This is its own failure and deserves its own words. A 200 whose body we
    cannot interpret produced UNAVAILABLE evidence with an empty reason, which
    is the worst possible thing to hand a reviewer: a signal was lost, and the
    record says nothing about why. Naming the field and the value received is
    what lets somebody widen the mapping in an afternoon rather than a week.
    """
    return EvidenceOut(
        evidence_id=f"ev_{uuid.uuid4().hex[:12]}",
        type=evidence_type,  # type: ignore[arg-type]
        status="UNAVAILABLE",
        source=source,  # type: ignore[arg-type]
        provider=PROVIDER if source == "CAMARA" else "SIMULATED",
        api=response.api,
        request_id=response.request_id,
        received_at=_now(),
        freshness="UNKNOWN",
        latency_ms=response.latency_ms,
        summary=(
            "The network answered, but not in a form this system recognises."
            + _NOT_AGAINST_THE_CLAIM
        ),
        normalized={
            "error_kind": "UNREADABLE",
            "received": detail,
            "payload_keys": sorted(response.payload.keys()),
        },
        failure_reason=detail,
    )


def location_verification(
    response: NacResponse, source: str, site_name: str, radius_m: int, freshness_seconds: int
) -> EvidenceOut:
    result = str(response.payload.get("verificationResult", "")).upper()
    observed_at = _parse_dt(response.payload.get("lastLocationTime"))

    if result == "TRUE":
        status, reliability = "SUPPORTED", 0.94
        summary = f"Device consistent with {site_name} (within {radius_m} m)."
    elif result == "FALSE":
        status, reliability = "CONFLICTING", 0.9
        summary = f"Device not consistent with {site_name} at the time observed by the network."
    elif result == "PARTIAL":
        status, reliability = "SUPPORTED", 0.6
        summary = f"Device partially consistent with {site_name}; the network could not confirm full containment."
    elif result in {"UNKNOWN", ""} or not response.payload:
        # UNKNOWN is the network explicitly declining to answer.
        return unavailable("LOCATION_VERIFICATION", response, source)
    else:
        return unreadable(
            "LOCATION_VERIFICATION",
            response,
            source,
            f"verificationResult={result} "
            f"(fields returned: {', '.join(sorted(response.payload))})",
        )

    return _base(
        "LOCATION_VERIFICATION",
        response,
        source,
        status,
        summary,
        {
            "verification_result": result,
            "expected_site": site_name,
            "radius_m": radius_m,
        },
        observed_at,
        reliability,
        freshness_seconds,
    )


def device_status(
    response: NacResponse, source: str, freshness_seconds: int, contemporaneous: bool
) -> EvidenceOut:
    value = str(response.payload.get("connectivityStatus", "")).upper()

    if value.startswith("CONNECTED"):
        status, reliability = "SUPPORTED", 0.85
        summary = "Device is attached to the mobile network."
    elif value == "NOT_CONNECTED":
        # Only contemporaneous observations can conflict with the claim.
        # "Not connected now" says little about a job completed hours ago.
        if contemporaneous:
            status, reliability = "CONFLICTING", 0.7
            summary = "Device is not attached to the network within the claimed service window."
        else:
            status, reliability = "STALE", 0.4
            summary = "Device is not attached to the network, but the observation is outside the claimed service window."
    elif not response.payload:
        return unavailable("DEVICE_STATUS", response, source)
    else:
        return unreadable(
            "DEVICE_STATUS",
            response,
            source,
            f"connectivityStatus={value or 'absent'} "
            f"(fields returned: {', '.join(sorted(response.payload)) or 'none'})",
        )

    return _base(
        "DEVICE_STATUS",
        response,
        source,
        status,
        summary,
        {"connectivity_status": value, "contemporaneous": contemporaneous},
        _now(),
        reliability,
        freshness_seconds,
    )


def device_reachability(
    response: NacResponse, source: str, freshness_seconds: int, contemporaneous: bool
) -> EvidenceOut:
    payload = response.payload

    # Nokia does not return the CAMARA v1 `reachabilityStatus` field. It
    # returns a boolean `reachable` alongside a `connectivity` channel and a
    # `lastStatusTime`, which is a different shape for the same fact. Read it
    # first, because on this operator it is the shape that actually arrives.
    if isinstance(payload.get("reachable"), bool):
        reachable = bool(payload["reachable"])
        channels = _channels(payload.get("connectivity"))
        observed = _parse_dt(payload.get("lastStatusTime"))

        if reachable:
            status, reliability = "SUPPORTED", 0.8
            phrase = _phrase(channels)
            summary = (
                f"Device is reachable on the network over {phrase}."
                if phrase
                else "Device is reachable on the network."
            )
        elif contemporaneous:
            status, reliability = "CONFLICTING", 0.6
            summary = "Device could not be reached within the claimed service window."
        else:
            status, reliability = "STALE", 0.35
            summary = "Device could not be reached, but outside the claimed service window."

        return _base(
            "DEVICE_REACHABILITY",
            response,
            source,
            status,
            summary,
            {
                "reachable": reachable,
                "connectivity": channels or None,
                "contemporaneous": contemporaneous,
            },
            # A real observation time, rather than assuming the answer describes
            # this instant — which is what freshness is supposed to measure.
            observed or _now(),
            reliability,
            freshness_seconds,
        )

    value = str(
        payload.get("reachabilityStatus")
        or payload.get("connectivityStatus")
        or ""
    ).upper()

    # Matched by shape rather than by an exact list. CAMARA specifies
    # CONNECTED_DATA / CONNECTED_SMS / NOT_CONNECTED, but the portal's own
    # example is named REACHABLE_SMS, and an operator returning a value one
    # word off the spec should not silently cost us the signal. A negative
    # prefix still wins, so a widened match cannot turn "not connected" into
    # support.
    negative = value.startswith(("NOT_", "UN", "DIS"))
    positive = not negative and ("CONNECTED" in value or "REACHABLE" in value)

    if positive:
        status, reliability = "SUPPORTED", 0.8
        summary = "Device is reachable on the network."
    elif negative:
        if contemporaneous:
            status, reliability = "CONFLICTING", 0.6
            summary = "Device could not be reached within the claimed service window."
        else:
            status, reliability = "STALE", 0.35
            summary = "Device could not be reached, but outside the claimed service window."
    elif not payload:
        return unavailable("DEVICE_REACHABILITY", response, source)
    else:
        return unreadable(
            "DEVICE_REACHABILITY",
            response,
            source,
            f"reachabilityStatus={value or 'absent'} "
            f"(fields returned: {', '.join(sorted(payload)) or 'none'})",
        )

    return _base(
        "DEVICE_REACHABILITY",
        response,
        source,
        status,
        summary,
        {"reachability_status": value, "contemporaneous": contemporaneous},
        _now(),
        reliability,
        freshness_seconds,
    )


def device_roaming(
    response: NacResponse, source: str, freshness_seconds: int, expected_country: int | None = None
) -> EvidenceOut:
    roaming = bool(response.payload.get("roaming", False))
    country = response.payload.get("countryCode")

    if not roaming:
        status, reliability = "SUPPORTED", 0.7
        summary = "Device is on its home network."
    elif expected_country is not None and country == expected_country:
        status, reliability = "SUPPORTED", 0.6
        summary = "Device is roaming inside the expected country."
    else:
        status, reliability = "CONFLICTING", 0.6
        summary = "Device is roaming outside the expected operating country."

    return _base(
        "DEVICE_ROAMING_STATUS",
        response,
        source,
        status,
        summary,
        {"roaming": roaming, "country_code": country},
        _now(),
        reliability,
        freshness_seconds,
    )
