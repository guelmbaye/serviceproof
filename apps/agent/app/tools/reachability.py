from __future__ import annotations

from app.camara import normalizer
from app.camara.client import NacResponse
from app.schemas import EvidenceOut
from app.tools.base import Tool, ToolContext


class ReachabilityTool(Tool):
    name = "check_reachability"
    evidence_type = "DEVICE_REACHABILITY"
    api_name = "Device Reachability Status"
    description = (
        "Ask the network whether the device can currently be reached. Third signal, "
        "used to reconcile a conflict between location and device status."
    )
    business_question = "Could the device be reached?"

    async def run(self, ctx: ToolContext) -> EvidenceOut:
        device = ctx.camara_device()

        if not device:
            return normalizer.unavailable(
                self.evidence_type,
                NacResponse(
                    ok=False, api=self.api_name, request_id="n/a", latency_ms=0,
                    error="No device mapping available for this claim.", error_kind="INVALID",
                ),
                "CAMARA",
            )

        scenario = ctx.demo_scenario()

        response, source = await self._execute(
            ctx,
            live_call=lambda: ctx.client.device_reachability(device),
            demo_call=lambda: ctx.demo.device_reachability(
                ctx.device.identifier if ctx.device else "", scenario
            ),
        )

        if not response.ok:
            return normalizer.unavailable(self.evidence_type, response, source)

        return normalizer.device_reachability(
            response, source, ctx.freshness_seconds, ctx.contemporaneous
        )
