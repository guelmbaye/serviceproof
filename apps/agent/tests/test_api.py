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
    assert set(body["tools"]) == {"verify_location", "get_device_status", "check_reachability"}
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
