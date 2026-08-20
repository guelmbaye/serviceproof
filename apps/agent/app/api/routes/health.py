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
    }


@router.get("/tools")
async def tools() -> dict:
    """The controlled tool surface, for documentation and the ops UI."""
    return {"tools": registry.specs()}
