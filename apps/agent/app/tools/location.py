from __future__ import annotations

from app.camara import normalizer
from app.camara.client import NacResponse
from app.schemas import EvidenceOut
from app.tools.base import Tool, ToolContext


class VerifyLocationTool(Tool):
    name = "verify_location"
    evidence_type = "LOCATION_VERIFICATION"
    api_name = "Location Verification"
    description = (
        "Ask the network whether the worker's device is inside the circle around the "
        "expected service site. Strongest single signal for a site-visit claim."
    )
    business_question = "Was the device consistent with the expected service site?"

    async def run(self, ctx: ToolContext) -> EvidenceOut:
        device = ctx.camara_device()
        site = ctx.site

        if not device or not site:
            return normalizer.unavailable(
                self.evidence_type,
                NacResponse(
                    ok=False,
                    api=self.api_name,
                    request_id="n/a",
                    latency_ms=0,
                    error="No device mapping or expected site available for this claim.",
                    error_kind="INVALID",
                ),
                "CAMARA",
            )

        radius = site.radius_m or ctx.policy.location_radius_m

        response, source = await self._execute(
            ctx,
            live_call=lambda: ctx.client.verify_location(
                device=device,
                latitude=site.latitude,
                longitude=site.longitude,
                radius_m=radius,
                max_age_seconds=ctx.freshness_seconds,
            ),
            demo_call=lambda: ctx.demo.verify_location(
                identifier=ctx.device.identifier if ctx.device else "",
                latitude=site.latitude,
                longitude=site.longitude,
                radius_m=radius,
            ),
        )

        if not response.ok:
            return normalizer.unavailable(self.evidence_type, response, source)

        return normalizer.location_verification(
            response, source, site.name, radius, ctx.freshness_seconds
        )
