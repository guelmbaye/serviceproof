from __future__ import annotations

import logging

from fastapi import APIRouter, Depends

from app.agent.orchestrator import EvidenceOrchestrator
from app.config import AGENT_VERSION, get_settings
from app.schemas import PlannerInfo, VerifyRequest, VerifyResponse
from app.security import require_internal_token

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/agent", tags=["agent"])


@router.post("/verify", response_model=VerifyResponse)
async def verify(payload: VerifyRequest, _: None = Depends(require_internal_token)) -> VerifyResponse:
    """Run one verification.

    Laravel ships the full context in this single POST, so the agent needs
    no callback and the whole cycle stays inside one bounded request.
    """
    settings = get_settings()

    try:
        return await EvidenceOrchestrator(settings).run(payload)
    except Exception as exc:  # noqa: BLE001
        # Fail closed. A crash in the intelligence layer must never be
        # reported as a verified claim.
        logger.exception("Verification run failed")

        return VerifyResponse(
            status="FAILED",
            agent_version=AGENT_VERSION,
            planner=PlannerInfo(mode="heuristic", provider=settings.llm_provider),
            failure_reason=f"Agent runtime error: {exc}",
        )
