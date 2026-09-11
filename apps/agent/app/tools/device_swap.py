from __future__ import annotations

from app.camara import normalizer
from app.camara.client import NacResponse
from app.schemas import EvidenceOut
from app.tools.base import Tool, ToolContext


class DeviceSwapTool(Tool):
    """Has the device behind this subscription changed recently?

    This is the corroboration that actually bears on a contested location.
    Device Status answers "was the handset on the network", which is true of
    almost every handset and says nothing about the claim. A recent device
    swap questions something narrower and far more relevant: whether the
    binding between the worker and the identifier we queried still holds.

    The wording matters more here than anywhere else in the product. A swap is
    never evidence that a technician cheated — people replace broken phones.
    It says the continuity supporting this assurance transaction changed, which
    is a reason for a person to look, not a conclusion about a person.
    """

    name = "check_device_swap"
    evidence_type = "DEVICE_SWAP"
    api_name = "Device Swap"
    description = (
        "Ask the network whether the device behind this subscription changed recently. "
        "Used when location is contested, to test whether the identifier still maps to "
        "the same physical device."
    )
    business_question = "Has the device behind this subscription changed recently?"

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
            live_call=lambda: ctx.client.device_swap(device),
            demo_call=lambda: ctx.demo.device_swap(
                ctx.device.identifier if ctx.device else "", scenario
            ),
        )

        if not response.ok:
            return normalizer.unavailable(self.evidence_type, response, source)

        return normalizer.device_swap(response, source, ctx.freshness_seconds)
