from __future__ import annotations

from datetime import datetime, timezone

import pytest

from app.camara.demo import SIMULATOR_HOME
from app.config import Settings
from app.schemas import (
    BudgetIn,
    ClaimIn,
    DemoIn,
    DeviceIn,
    EntitlementIn,
    ExpectedSite,
    PolicyIn,
    RequestMeta,
    VerifyRequest,
    WorkOrderIn,
)

# Single source of truth: whatever the demo adapter believes the simulator's
# position to be, the tests believe too. Hardcoding it here once meant a
# corrected coordinate broke six tests for no reason other than duplication.
SIM_LAT, SIM_LNG = SIMULATOR_HOME

# ~940 km away, so a claim placed here can only ever conflict.
FAR_LAT, FAR_LNG = 47.48627616952785, 19.07915612501993


@pytest.fixture
def settings() -> Settings:
    return Settings(
        llm_provider="heuristic",
        camara_mode="demo",
        nac_rapidapi_key="",
        agent_internal_token="test-token",
    )


def build_request(
    *,
    latitude: float = SIM_LAT,
    longitude: float = SIM_LNG,
    radius_m: int = 1000,
    scenario: str | None = None,
    required: list[str] | None = None,
    optional: list[str] | None = None,
    max_tool_calls: int = 3,
    allow_partial: bool = True,
    notes: str | None = None,
    identifier: str = "+99999991000",
    entitlement=None,
) -> VerifyRequest:
    return VerifyRequest(
        request=RequestMeta(verification_run_id="run-1", organization_id="org-1"),
        claim=ClaimIn(
            id="claim-1",
            reference="CLM-1001",
            claimed_at=datetime.now(timezone.utc),
            untrusted_notes=notes,
        ),
        work_order=WorkOrderIn(
            reference="WO-1042",
            customer="ABC Telecom",
            expected_site=ExpectedSite(
                name="Site A", latitude=latitude, longitude=longitude, radius_m=radius_m
            ),
        ),
        # An active entitlement by default: these tests are about evidence
        # behaviour, and the gate has its own tests. Pass entitlement=... to
        # exercise a refusal.
        device=DeviceIn(
            reference="DEV-001",
            identifier=identifier,
            entitlement=entitlement or EntitlementIn(status="ACTIVE", reference="BIND-001"),
        ),
        policy=PolicyIn(
            required_evidence=required or ["LOCATION_VERIFICATION"],
            optional_evidence=optional if optional is not None else ["DEVICE_STATUS", "DEVICE_REACHABILITY"],
            max_tool_calls=max_tool_calls,
            allow_partial=allow_partial,
            allowed_tools=["verify_location", "get_device_status", "check_reachability"],
        ),
        budget=BudgetIn(max_tool_calls=max_tool_calls),
        tools=["verify_location", "get_device_status", "check_reachability"],
        demo=DemoIn(force_mode="demo", scenario=scenario),
    )
