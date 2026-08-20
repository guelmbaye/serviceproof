"""The wire contract with Nokia Network as Code.

These endpoints, headers and request bodies were confirmed against the Nokia
developer portal for the MENA Ignite app. Getting one character wrong costs a
live demo and produces UNAVAILABLE evidence rather than a loud failure — which
is the right behaviour at runtime and a terrible way to find out. So the exact
request is pinned here.

Nothing hits the network: an httpx MockTransport captures what would have been
sent and the assertions read it back.
"""

from __future__ import annotations

import json

import httpx
import pytest

from app.camara.client import NacClient
from app.config import Settings

BASE = "https://network-as-code.p-eu.apihub.nokia.io"
DEVICE = {"phoneNumber": "+99999991000"}


@pytest.fixture
def live_settings() -> Settings:
    return Settings(nac_rapidapi_key="TEST_KEY", camara_mode="live")


class Recorder:
    """Captures the outbound request and answers with a canned payload."""

    def __init__(self, payload: dict | None = None) -> None:
        self.payload = payload if payload is not None else {}
        self.request: httpx.Request | None = None

    def __call__(self, request: httpx.Request) -> httpx.Response:
        self.request = request
        return httpx.Response(
            200, json=self.payload, headers={"x-correlator": "nokia-corr-abc123"}
        )

    @property
    def url(self) -> str:
        assert self.request is not None
        return str(self.request.url)

    @property
    def body(self) -> dict:
        assert self.request is not None
        return json.loads(self.request.content or b"{}")

    @property
    def headers(self) -> httpx.Headers:
        assert self.request is not None
        return self.request.headers


async def call(settings: Settings, recorder: Recorder, capability, *args, **kwargs):
    client = httpx.AsyncClient(
        base_url=settings.nac_base_url, transport=httpx.MockTransport(recorder)
    )
    nac = NacClient(settings, client=client)
    try:
        return await getattr(nac, capability)(*args, **kwargs)
    finally:
        await client.aclose()


# ── endpoints ────────────────────────────────────────────────────────────


async def test_location_verification_endpoint_and_body(live_settings):
    recorder = Recorder({"verificationResult": "FALSE"})

    await call(
        live_settings,
        recorder,
        "verify_location",
        device=DEVICE,
        latitude=50.735851,
        longitude=7.10066,
        radius_m=50000,
        max_age_seconds=60,
    )

    assert recorder.url == f"{BASE}/location-verification/v1/verify"
    assert recorder.request is not None and recorder.request.method == "POST"
    assert recorder.body == {
        "device": {"phoneNumber": "+99999991000"},
        "area": {
            "areaType": "CIRCLE",
            "center": {"latitude": 50.735851, "longitude": 7.10066},
            "radius": 50000,
        },
        "maxAge": 60,
    }


async def test_location_retrieval_endpoint_and_body(live_settings):
    recorder = Recorder()

    await call(live_settings, recorder, "location_retrieval", DEVICE, 60)

    assert recorder.url == f"{BASE}/location-retrieval/v0/retrieve"
    assert recorder.body == {"device": DEVICE, "maxAge": 60}


async def test_device_status_uses_the_connectivity_operation(live_settings):
    recorder = Recorder({"connectivityStatus": "CONNECTED_SMS"})

    await call(live_settings, recorder, "device_status", DEVICE)

    assert recorder.url == f"{BASE}/device-status/v0/connectivity"
    assert recorder.body == {"device": DEVICE}


async def test_reachability_uses_its_own_v1_api(live_settings):
    """Not the Device Status operation — reachability has a dedicated API."""
    recorder = Recorder({"reachabilityStatus": "CONNECTED_SMS"})

    await call(live_settings, recorder, "device_reachability", DEVICE)

    assert recorder.url == f"{BASE}/device-status/device-reachability-status/v1/retrieve"
    assert recorder.body == {"device": DEVICE}


async def test_roaming_prefers_the_dedicated_v1_api(live_settings):
    """Roaming exists twice; v1 is the one we build on."""
    recorder = Recorder({"roaming": False, "countryCode": 212})

    await call(live_settings, recorder, "device_roaming", DEVICE)

    assert recorder.url == f"{BASE}/device-status/device-roaming-status/v1/retrieve"
    assert "/device-status/v0/roaming" not in recorder.url


# ── headers ──────────────────────────────────────────────────────────────


async def test_the_rapidapi_credentials_travel_on_every_call(live_settings):
    recorder = Recorder({"connectivityStatus": "CONNECTED_DATA"})

    await call(live_settings, recorder, "device_status", DEVICE)

    assert recorder.headers["x-rapidapi-key"] == "TEST_KEY"
    assert recorder.headers["x-rapidapi-host"] == "network-as-code.nokia.rapidapi.com"
    assert recorder.headers["content-type"] == "application/json"


async def test_every_call_carries_a_camara_correlator(live_settings):
    recorder = Recorder({"connectivityStatus": "CONNECTED_DATA"})

    await call(live_settings, recorder, "device_status", DEVICE)

    assert recorder.headers["x-correlator"], "x-correlator is CAMARA's correlation header."


async def test_the_operators_correlator_wins_over_ours(live_settings):
    """The id on the evidence must be the one Nokia can look up."""
    recorder = Recorder({"connectivityStatus": "CONNECTED_DATA"})

    response = await call(live_settings, recorder, "device_status", DEVICE)

    assert response.request_id == "nokia-corr-abc123"
    assert response.request_id != recorder.headers["x-correlator"]


# ── credentials ──────────────────────────────────────────────────────────


async def test_no_call_is_attempted_without_a_key():
    """Fail before the wire rather than sending an unauthenticated request."""
    recorder = Recorder()
    settings = Settings(nac_rapidapi_key="", camara_mode="live")

    response = await call(settings, recorder, "device_status", DEVICE)

    assert recorder.request is None, "Nothing should have been sent."
    assert response.ok is False
    assert response.error_kind == "AUTH"


def test_the_gateway_host_is_nokias_not_rapidapis():
    """The key is RapidAPI's; the endpoint is not. Easy and costly to confuse."""
    settings = Settings()

    assert settings.nac_base_url == BASE
    assert "rapidapi.com" not in settings.nac_base_url
    assert settings.nac_rapidapi_host.endswith("rapidapi.com")


def test_no_path_carries_a_gateway_prefix():
    """The CAMARA path is the path — there is no passthrough segment."""
    settings = Settings()

    paths = [
        settings.nac_path_location_verification,
        settings.nac_path_location_retrieval,
        settings.nac_path_device_status,
        settings.nac_path_device_roaming,
        settings.nac_path_device_reachability,
    ]

    for path in paths:
        assert path.startswith("/"), path
        assert "passthrough" not in path, path
        assert "/camara/" not in path, path


# ── how failures are classified ──────────────────────────────────────────


@pytest.mark.parametrize(
    ("status", "expected"),
    [
        (400, "UNSUPPORTED"),   # the device is not one the network knows
        (404, "UNSUPPORTED"),
        (422, "UNSUPPORTED"),
        (429, "RATE_LIMIT"),    # obtainable, just not now
        (401, "AUTH"),
        (403, "AUTH"),
        (409, "HTTP"),          # unexpected client error, no special meaning
        (500, "SERVER"),        # the operator's problem, worth retrying
        (503, "SERVER"),
    ],
)
async def test_http_failures_are_classified_by_what_they_mean(
    live_settings, status, expected
):
    """A 400 and a 503 need different responses from an operations team, so
    they must not collapse into one 'HTTP' bucket."""

    def refuse(request: httpx.Request) -> httpx.Response:
        return httpx.Response(status, json={"message": "no"})

    client = httpx.AsyncClient(
        base_url=live_settings.nac_base_url, transport=httpx.MockTransport(refuse)
    )
    nac = NacClient(live_settings, client=client)
    try:
        response = await nac.device_status(DEVICE)
    finally:
        await client.aclose()

    assert response.ok is False
    assert response.error_kind == expected
