from __future__ import annotations

from fastapi import APIRouter

from app.config import AGENT_VERSION, get_settings
from app.tools import registry

router = APIRouter(tags=["health"])


@router.get("/health")
async def health() -> dict:
    settings = get_settings()

    return {
        "status": "ok",
        "service": "serviceproof-agent",
        "version": AGENT_VERSION,
        "planner": {
            "provider": settings.llm_provider,
            # False means the deterministic planner is doing the work — the
            # loop still runs, it simply does not consult a model.
            "llm_enabled": settings.llm_enabled,
        },
        "camara": {
            "mode": settings.camara_mode,
            "live_credentials": settings.has_live_credentials,
            "provider": "Nokia Network as Code",
        },
        "tools": [spec["name"] for spec in registry.specs()],

        # The paths actually in use, not the ones in the source.
        #
        # Every capability path is overridable from the environment, which is
        # the right design — a wrong path should cost a config change, not a
        # release. It also means a corrected default silently loses to a stale
        # value in a deployed .env, and the only symptom is a 404 that looks
        # identical to an unsubscribed capability.
        #
        # One curl now answers "which path is this container calling", instead
        # of a redeploy-and-see cycle.
        "capability_paths": {
            "location_verification": settings.nac_path_location_verification,
            "device_swap": settings.nac_path_device_swap,
            "device_status": settings.nac_path_device_status,
            "device_reachability": settings.nac_path_device_reachability,
        },
        "base_url": settings.nac_base_url,
    }


@router.get("/tools")
async def tools() -> dict:
    """The controlled tool surface, for documentation and the ops UI."""
    return {"tools": registry.specs()}
