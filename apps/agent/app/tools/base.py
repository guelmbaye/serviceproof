"""Controlled tool surface exposed to the agent.

The model never gets arbitrary network access. It gets a fixed set of
narrow tools with declared inputs, and the adapter below decides whether
a call goes to the live network or to the labelled demo fallback.
"""

from __future__ import annotations

import logging
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from datetime import datetime, timezone

from app.camara.client import NacClient, NacResponse
from app.camara.demo import DemoNetwork
from app.config import Settings
from app.schemas import ClaimIn, DeviceIn, ExpectedSite, PolicyIn

logger = logging.getLogger(__name__)


@dataclass
class ToolContext:
    settings: Settings
    client: NacClient
    demo: DemoNetwork
    policy: PolicyIn
    claim: ClaimIn
    device: DeviceIn | None
    site: ExpectedSite | None
    mode: str = "auto"  # auto | live | demo
    used_demo_fallback: bool = field(default=False)

    @property
    def contemporaneous(self) -> bool:
        """Is a device signal observed now close enough to the claimed time
        to be treated as contemporaneous evidence rather than context?"""
        claimed = self.claim.claimed_at
        if not claimed:
            return True
        if claimed.tzinfo is None:
            claimed = claimed.replace(tzinfo=timezone.utc)
        gap = abs((datetime.now(timezone.utc) - claimed).total_seconds())
        return gap <= self.settings.contemporaneity_window_seconds

    @property
    def freshness_seconds(self) -> int:
        return self.policy.freshness_seconds or self.settings.default_max_age_seconds

    def camara_device(self) -> dict | None:
        return self.device.camara_device() if self.device else None

    def demo_scenario(self) -> str:
        site = self.site
        return self.demo.resolve(
            self.device.identifier if self.device else "",
            site.latitude if site else 0.0,
            site.longitude if site else 0.0,
            site.radius_m if site else 1000,
        )

    def mark_fallback(self) -> None:
        self.used_demo_fallback = True


class Tool(ABC):
    """A single CAMARA capability, exposed under a stable contract."""

    name: str
    evidence_type: str
    api_name: str
    description: str
    business_question: str

    @abstractmethod
    async def run(self, ctx: ToolContext):  # -> EvidenceOut
        ...

    def spec(self) -> dict:
        """Machine-readable contract handed to the planner."""
        return {
            "name": self.name,
            "evidence_type": self.evidence_type,
            "api": self.api_name,
            "description": self.description,
            "business_question": self.business_question,
        }

    # ── shared execution strategy ────────────────────────────────────────

    async def _execute(self, ctx: ToolContext, live_call, demo_call) -> tuple[NacResponse, str]:
        """Run the capability and report which source actually answered.

        auto : live first, clearly-labelled fallback if the network fails
        live : live only — a failure stays a failure, honestly reported
        demo : fallback only, always tagged DEMO_FALLBACK
        """
        if ctx.mode == "demo" or (ctx.mode == "auto" and not ctx.settings.has_live_credentials):
            ctx.mark_fallback()
            return await _maybe_await(demo_call()), "DEMO_FALLBACK"

        response = await live_call()

        if response.ok or ctx.mode == "live":
            return response, "CAMARA"

        logger.warning(
            "Live CAMARA call failed (%s: %s); switching to the labelled demo fallback.",
            response.error_kind,
            response.error,
        )
        ctx.mark_fallback()

        fallback = await _maybe_await(demo_call())

        # Carry the real cause across. The simulated response has its own
        # invented reason, and on its own it contradicts the logs — evidence
        # reading "network timeout" for a run where the operator answered
        # HTTP 500 is worse than no reason at all.
        fallback.fallback_from = f"{response.error_kind}: {response.error}"

        return fallback, "DEMO_FALLBACK"


async def _maybe_await(value):
    if hasattr(value, "__await__"):
        return await value
    return value
