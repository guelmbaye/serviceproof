"""HTTP surface: health, tool catalogue, and the guarded verify endpoint."""

from __future__ import annotations

import os

os.environ.setdefault("AGENT_INTERNAL_TOKEN", "dev-internal-token")
os.environ.setdefault("CAMARA_MODE", "demo")
os.environ.setdefault("LLM_PROVIDER", "heuristic")

from fastapi.testclient import TestClient  # noqa: E402

from app.main import app  # noqa: E402
from tests.conftest import build_request  # noqa: E402

client = TestClient(app)
AUTH = {"X-Internal-Token": "dev-internal-token"}


def test_health_reports_the_planner_and_camara_mode():
    body = client.get("/health").json()

    assert body["status"] == "ok"
    assert set(body["tools"]) == {
        "verify_location",
        "check_device_swap",
        "get_device_status",
        "check_reachability",
    }
    assert body["camara"]["provider"] == "Nokia Network as Code"


def test_the_verify_endpoint_rejects_an_unauthenticated_caller():
    response = client.post("/agent/verify", json=build_request().model_dump(mode="json"))

    assert response.status_code == 403


def test_a_full_verification_round_trip():
    response = client.post(
        "/agent/verify",
        headers=AUTH,
        json=build_request().model_dump(mode="json"),
    )

    assert response.status_code == 200
    body = response.json()

    assert body["status"] == "COMPLETED"
    assert body["decision"]["state"] == "VERIFIED"
    assert body["evidence"][0]["request_id"]
    assert body["trace"][0]["event_type"] == "AGENT_STARTED"


def test_a_malformed_bundle_is_rejected_before_any_network_call():
    response = client.post("/agent/verify", headers=AUTH, json={"claim": {}})

    assert response.status_code == 422


def test_health_exposes_the_paths_actually_in_use():
    """The diagnostic that ends a class of round-trip.

    Every capability path is overridable from the environment, so a corrected
    default loses silently to a stale value in a deployed .env — and the only
    symptom is a 404 indistinguishable from an unsubscribed capability. Twice
    now that has cost a redeploy to discover.
    """
    body = TestClient(app).get("/health").json()

    paths = body["capability_paths"]

    assert set(paths) == {
        "location_verification",
        "device_swap",
        "device_status",
        "device_reachability",
    }

    # Nokia's passthrough mounting, not the CAMARA standard path.
    assert paths["device_swap"].startswith("/passthrough/camara/")
    assert "device-swap/device-swap" in paths["device_swap"]

    assert body["base_url"].startswith("https://")


def test_health_shows_which_key_the_container_holds():
    """A fingerprint, not the key.

    `docker compose restart` keeps the old environment; only `up -d` recreates
    with the new one. That distinction cost an afternoon of a deployment where
    the .env held a working key and the container held a rotated one, with no
    way to see the difference short of an exec.

    Six characters answer it from outside and reveal nothing useful.
    """
    from app.config import get_settings

    get_settings.cache_clear()
    body = TestClient(app).get("/health").json()

    fingerprint = body["camara"]["key_fingerprint"]

    if body["camara"]["live_credentials"]:
        assert fingerprint is not None
        assert fingerprint.startswith("…")
        assert len(fingerprint) == 7, "Six characters and the ellipsis, no more."
    else:
        assert fingerprint is None
