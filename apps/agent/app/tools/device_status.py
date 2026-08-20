from __future__ import annotations

from app.camara import normalizer
from app.camara.client import NacResponse
from app.schemas import EvidenceOut
from app.tools.base import Tool, ToolContext


class DeviceStatusTool(Tool):
    name = "get_device_status"
    evidence_type = "DEVICE_STATUS"
    api_name = "Device Status"
    description = (
        "Ask the network whether the worker's device is attached to the mobile network. "
        "Corroborating signal, typically used when the location result is contested."
    )
    business_question = "Was the device active on the network?"

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
            live_call=lambda: ctx.client.device_status(device),
            demo_call=lambda: ctx.demo.device_status(
                ctx.device.identifier if ctx.device else "", scenario
            ),
        )

        if not response.ok:
            return normalizer.unavailable(self.evidence_type, response, source)

        return normalizer.device_status(
            response, source, ctx.freshness_seconds, ctx.contemporaneous
        )
