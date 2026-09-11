from __future__ import annotations

from datetime import datetime, timedelta, timezone

from app.camara.client import NacResponse
from app.camara.normalizer import (
    _parse_dt,
    device_reachability,
    device_swap,
    device_status,
    location_verification,
    unavailable,
)


def response(payload: dict) -> NacResponse:
    return NacResponse(
        ok=True, api="Location Verification", request_id="req_123",
        latency_ms=210, status_code=200, payload=payload,
    )


def test_true_becomes_supported_with_provenance():
    now = datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
    item = location_verification(
        response({"verificationResult": "TRUE", "lastLocationTime": now}),
        "CAMARA", "Site A", 1000, 900,
    )

    assert item.status == "SUPPORTED"
    assert item.request_id == "req_123"
    assert item.provider == "NOKIA_NAC"
    assert item.payload_hash.startswith("sha256:")


def test_false_becomes_conflicting_not_fraud():
    item = location_verification(response({"verificationResult": "FALSE"}), "CAMARA", "Site B", 500, 900)

    assert item.status == "CONFLICTING"
    assert "fraud" not in (item.summary or "").lower()


def test_unknown_is_treated_as_unavailable():
    item = location_verification(response({"verificationResult": "UNKNOWN"}), "CAMARA", "Site A", 1000, 900)

    assert item.status == "UNAVAILABLE"


def test_an_old_observation_is_downgraded_to_stale():
    old = (datetime.now(timezone.utc) - timedelta(hours=3)).isoformat().replace("+00:00", "Z")
    item = location_verification(
        response({"verificationResult": "TRUE", "lastLocationTime": old}),
        "CAMARA", "Site A", 1000, 900,
    )

    assert item.status == "STALE"
    assert item.freshness == "STALE"


def test_a_non_contemporaneous_device_signal_does_not_conflict():
    payload = response({"connectivityStatus": "NOT_CONNECTED"})

    contemporaneous = device_status(payload, "CAMARA", 900, contemporaneous=True)
    historical = device_status(payload, "CAMARA", 900, contemporaneous=False)

    assert contemporaneous.status == "CONFLICTING"
    assert historical.status == "STALE"


def test_a_failed_call_carries_its_reason_and_no_verdict():
    failure = NacResponse(
        ok=False, api="Device Status", request_id="req_x", latency_ms=8000,
        error="timeout", error_kind="TIMEOUT",
    )

    item = unavailable("DEVICE_STATUS", failure, "CAMARA")

    assert item.status == "UNAVAILABLE"
    assert item.failure_reason == "timeout"
    assert "absence of evidence" in (item.summary or "")


# ── timestamps as operators actually send them ───────────────────────────


def test_a_timestamp_without_an_offset_does_not_crash():
    """The live failure this test exists for.

    Nokia returns lastLocationTime with no timezone, so fromisoformat yields a
    naive datetime and subtracting it from an aware now() raised TypeError. The
    tool blew up, the orchestrator swallowed it, and a successful 200 from the
    network became a run with no evidence at all.
    """
    naive = datetime.now(timezone.utc).replace(tzinfo=None).isoformat()

    item = location_verification(
        response({"verificationResult": "TRUE", "lastLocationTime": naive}),
        "CAMARA", "Site A", 1000, 900,
    )

    assert item.status == "SUPPORTED"
    assert item.observed_at is not None
    assert item.observed_at.tzinfo is not None, "Every timestamp leaves here aware."
    assert item.age_seconds is not None and item.age_seconds >= 0


def test_every_accepted_timestamp_format_is_aware():
    for value in (
        "2026-08-17T23:40:12",           # naive, as Nokia sends it
        "2026-08-17T23:40:12Z",
        "2026-08-17T23:40:12.123456",
        "2026-08-17T23:40:12+02:00",
        "2026-08-17 23:40:12",           # space instead of the ISO 'T'
    ):
        parsed = _parse_dt(value)
        assert parsed is not None, value
        assert parsed.tzinfo is not None, value

    assert _parse_dt("not a date") is None
    assert _parse_dt(None) is None
    assert _parse_dt(1234) is None


def test_an_offset_is_respected_when_one_is_given():
    """Assuming UTC is a fallback for naive values, not an override."""
    aware = _parse_dt("2026-08-17T23:40:12+02:00")
    utc = _parse_dt("2026-08-17T21:40:12Z")

    assert aware == utc


def test_a_timestamp_from_the_future_is_not_trusted_as_an_age():
    """If the provider was not sending UTC, every derived age is fiction."""
    future = (datetime.now(timezone.utc) + timedelta(hours=3)).isoformat()

    item = location_verification(
        response({"verificationResult": "TRUE", "lastLocationTime": future}),
        "CAMARA", "Site A", 1000, 900,
    )

    assert item.status == "SUPPORTED", "A readable result is not thrown away."
    assert item.age_seconds is None
    assert item.freshness == "UNKNOWN"


def test_ordinary_clock_skew_rounds_to_zero():
    slightly_ahead = (datetime.now(timezone.utc) + timedelta(seconds=20)).isoformat()

    item = location_verification(
        response({"verificationResult": "TRUE", "lastLocationTime": slightly_ahead}),
        "CAMARA", "Site A", 1000, 900,
    )

    assert item.age_seconds == 0
    assert item.freshness == "CURRENT"


# ── what a reviewer is told when things fail ─────────────────────────────


def failure(kind: str, message: str, fallback_from: str | None = None) -> NacResponse:
    return NacResponse(
        ok=False, api="Location Verification", request_id="req_x", latency_ms=120,
        error=message, error_kind=kind, fallback_from=fallback_from,
    )


def test_a_rejected_device_is_not_called_a_temporary_outage():
    """HTTP 400 means the network cannot answer for this device at all.

    Reporting that as "temporarily unavailable" sends an operations team off
    to wait for a recovery that will never come, when what they need is to fix
    the device mapping.
    """
    item = unavailable("DEVICE_STATUS", failure("UNSUPPORTED", "HTTP 400."), "CAMARA")

    assert item.status == "UNAVAILABLE"
    assert "provisioning" in (item.summary or "")
    assert "temporarily" not in (item.summary or "")
    assert "not evidence against the claim" in (item.summary or "")


def test_a_timeout_is_still_described_as_temporary():
    item = unavailable("DEVICE_STATUS", failure("TIMEOUT", "timed out"), "CAMARA")

    assert "temporarily unavailable" in (item.summary or "")


def test_the_fallback_reports_the_live_failure_that_caused_it():
    """The reason on the evidence has to match the reason in the logs.

    The simulated adapter invents its own failure text. On its own it said
    "network timeout" for a run where the operator had answered HTTP 500 —
    a contradiction a reviewer cannot resolve.
    """
    item = unavailable(
        "LOCATION_VERIFICATION",
        failure("TIMEOUT", "Simulated network timeout.",
                fallback_from="SERVER: Network API returned HTTP 500."),
        "DEMO_FALLBACK",
    )

    assert "HTTP 500" in (item.failure_reason or "")
    assert "Simulated network timeout" in (item.failure_reason or "")
    assert item.failure_reason is not None and ".." not in item.failure_reason
    assert item.normalized["fell_back_from"] == "SERVER: Network API returned HTTP 500."


def test_a_successful_fallback_says_it_replaced_a_live_call():
    """Simulated evidence that stood in for a live call must say so."""
    ok = NacResponse(
        ok=True, api="Location Verification", request_id="demo_1", latency_ms=40,
        status_code=200, payload={"verificationResult": "TRUE"},
        fallback_from="SERVER: Network API returned HTTP 500.",
    )

    item = location_verification(ok, "DEMO_FALLBACK", "Site A", 1000, 900)

    assert item.status == "SUPPORTED"
    assert "Substituted after the live call failed" in (item.summary or "")
    assert item.normalized["fell_back_from"].startswith("SERVER")


# ── answers we cannot read ───────────────────────────────────────────────


def ok_response(api: str, payload: dict) -> NacResponse:
    return NacResponse(
        ok=True, api=api, request_id="req_ok", latency_ms=1254,
        status_code=200, payload=payload,
    )


def test_a_status_one_word_off_the_spec_still_counts():
    """The portal's own example is named REACHABLE_SMS, not CONNECTED_SMS.

    Matching an exact list meant a live 200 became UNAVAILABLE and the signal
    was lost. Matching by shape keeps it, and the negative prefix check means
    a wider match cannot turn a refusal into support.
    """
    for value in ("REACHABLE_SMS", "CONNECTED_DATA", "CONNECTED_SMS", "REACHABLE"):
        item = device_reachability(
            ok_response("Device Reachability Status", {"reachabilityStatus": value}),
            "CAMARA", 900, contemporaneous=True,
        )
        assert item.status == "SUPPORTED", value


def test_a_widened_match_cannot_swallow_a_negative():
    for value in ("NOT_CONNECTED", "UNREACHABLE", "DISCONNECTED"):
        item = device_reachability(
            ok_response("Device Reachability Status", {"reachabilityStatus": value}),
            "CAMARA", 900, contemporaneous=True,
        )
        assert item.status == "CONFLICTING", value


def test_an_unreadable_200_says_what_it_actually_received():
    """The signal is still lost, but not silently.

    An unrecognised 200 used to produce UNAVAILABLE with an empty reason: a
    reviewer saw a blank, and nobody could tell whether the network had
    answered at all.
    """
    item = device_reachability(
        ok_response(
            "Device Reachability Status",
            {"status": "OK", "lastStatusTime": "2026-08-19T18:09:52Z"},
        ),
        "CAMARA", 900, contemporaneous=True,
    )

    assert item.status == "UNAVAILABLE"
    assert item.failure_reason, "An unreadable answer must still carry a reason."
    assert "lastStatusTime" in item.failure_reason
    assert "status" in item.failure_reason
    assert item.normalized["error_kind"] == "UNREADABLE"
    assert "not evidence against the claim" in (item.summary or "")


def test_an_empty_body_is_unavailable_not_unreadable():
    """Nothing to describe is a different failure from something unrecognised."""
    item = device_reachability(
        ok_response("Device Reachability Status", {}), "CAMARA", 900, contemporaneous=True
    )

    assert item.normalized["error_kind"] != "UNREADABLE"


def test_an_unrecognised_location_result_is_named_too():
    item = location_verification(
        ok_response("Location Verification", {"verificationResult": "MAYBE"}),
        "CAMARA", "Site A", 1000, 900,
    )

    assert item.status == "UNAVAILABLE"
    assert "MAYBE" in (item.failure_reason or "")


def test_nokias_reachability_shape_is_read():
    """Nokia does not send CAMARA's `reachabilityStatus`.

    It sends a boolean `reachable` with a `connectivity` channel and a
    `lastStatusTime`. Reading only the spec field cost the whole signal on
    every live run, and the failure was silent until the evidence started
    naming the fields it had actually received.
    """
    observed = datetime.now(timezone.utc).replace(tzinfo=None).isoformat(timespec="seconds")

    item = device_reachability(
        ok_response(
            "Device Reachability Status",
            {
                "device": {"phoneNumber": "+99999991001"},
                "reachable": True,
                "connectivity": ["DATA"],
                "lastStatusTime": observed,
            },
        ),
        "CAMARA", 900, contemporaneous=True,
    )

    assert item.status == "SUPPORTED"
    assert "data" in (item.summary or "")
    assert item.normalized["reachable"] is True
    assert item.normalized["connectivity"] == ["DATA"]


def test_the_operators_own_timestamp_drives_freshness():
    """lastStatusTime is a real observation time, not this instant."""
    old = (datetime.now(timezone.utc) - timedelta(hours=2)).replace(tzinfo=None).isoformat()

    item = device_reachability(
        ok_response(
            "Device Reachability Status",
            {"reachable": True, "connectivity": "SMS", "lastStatusTime": old},
        ),
        "CAMARA", 900, contemporaneous=True,
    )

    assert item.age_seconds is not None and item.age_seconds > 900
    assert item.status == "STALE", "An observation older than the policy window is stale."


def test_reachable_false_conflicts_within_the_window():
    item = device_reachability(
        ok_response("Device Reachability Status", {"reachable": False, "connectivity": None}),
        "CAMARA", 900, contemporaneous=True,
    )

    assert item.status == "CONFLICTING"
    assert item.normalized["reachable"] is False


def test_reachable_false_outside_the_window_is_only_stale():
    """Unreachable now says little about a job finished hours ago."""
    item = device_reachability(
        ok_response("Device Reachability Status", {"reachable": False}),
        "CAMARA", 900, contemporaneous=False,
    )

    assert item.status == "STALE"


def test_the_camara_spec_shape_still_works():
    """Nokia's shape is read first; it does not replace the standard one."""
    item = device_reachability(
        ok_response("Device Reachability Status", {"reachabilityStatus": "CONNECTED_DATA"}),
        "CAMARA", 900, contemporaneous=True,
    )

    assert item.status == "SUPPORTED"


def test_the_connectivity_list_is_read_as_channels_not_as_python():
    """Nokia sends connectivity as a list.

    Coercing it with str() put "reachable over ['sms']" in front of a
    reviewer — Python syntax leaking into an evidence record.
    """
    single = device_reachability(
        ok_response("Device Reachability Status",
                    {"reachable": True, "connectivity": ["SMS"]}),
        "CAMARA", 900, contemporaneous=True,
    )
    assert single.summary == "Device is reachable on the network over sms."
    assert single.normalized["connectivity"] == ["SMS"]

    several = device_reachability(
        ok_response("Device Reachability Status",
                    {"reachable": True, "connectivity": ["DATA", "SMS"]}),
        "CAMARA", 900, contemporaneous=True,
    )
    assert several.summary == "Device is reachable on the network over data and sms."

    # A bare string is accepted too: one operator's array is another's scalar.
    scalar = device_reachability(
        ok_response("Device Reachability Status",
                    {"reachable": True, "connectivity": "DATA"}),
        "CAMARA", 900, contemporaneous=True,
    )
    assert scalar.normalized["connectivity"] == ["DATA"]

    for empty in ([], None, ""):
        item = device_reachability(
            ok_response("Device Reachability Status",
                        {"reachable": True, "connectivity": empty}),
            "CAMARA", 900, contemporaneous=True,
        )
        assert item.summary == "Device is reachable on the network."
        assert item.normalized["connectivity"] is None


def test_no_evidence_summary_leaks_python_syntax():
    """A cheap guard against the whole class: brackets and quotes in a
    sentence mean a container was stringified somewhere."""
    payloads = [
        {"reachable": True, "connectivity": ["SMS"]},
        {"reachable": True, "connectivity": ["DATA", "SMS"]},
        {"reachable": False, "connectivity": []},
        {"reachabilityStatus": "CONNECTED_DATA"},
    ]

    for payload in payloads:
        item = device_reachability(
            ok_response("Device Reachability Status", payload),
            "CAMARA", 900, contemporaneous=True,
        )
        summary = item.summary or ""
        for token in ("[", "]", "{", "}", "'"):
            assert token not in summary, f"{token!r} in {summary!r}"


# ── device swap: continuity, not accusation ──────────────────────────────


def test_a_recent_swap_conflicts_without_accusing_anyone():
    """The wording matters more here than anywhere else in the product.

    People replace broken handsets. A swap is never evidence that a technician
    cheated — it says the binding supporting this claim is not continuous,
    which is a reason for a person to look, not a conclusion about a person.
    """
    item = device_swap(
        ok_response("Device Swap", {"swapped": True}), "CAMARA", 900
    )

    assert item.status == "CONFLICTING"
    assert "binding" in (item.summary or "")

    summary = (item.summary or "").lower()
    for word in ("fraud", "cheat", "lying", "suspicious", "deliberate"):
        assert word not in summary, f'"{word}" has no place in this summary'


def test_no_swap_supports_the_binding():
    item = device_swap(
        ok_response("Device Swap", {"swapped": False}), "CAMARA", 900
    )

    assert item.status == "SUPPORTED"
    assert item.normalized["swapped"] is False


def test_an_unreadable_swap_answer_names_what_it_received():
    item = device_swap(
        ok_response("Device Swap", {"status": "UNKNOWN"}), "CAMARA", 900
    )

    assert item.status == "UNAVAILABLE"
    assert "status" in (item.failure_reason or "")
