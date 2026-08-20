"""Nokia Network as Code client — the CAMARA access layer.

This is the only place in the codebase that knows Nokia-specific details.
Everything above it works with normalised evidence, so swapping in another
operator or aggregator later is an adapter change, not a rewrite.
"""

from __future__ import annotations

import logging
import time
import uuid
from dataclasses import dataclass, field
from typing import Any

import httpx

from app.config import Settings

logger = logging.getLogger(__name__)


@dataclass
class NacResponse:
    ok: bool
    api: str
    request_id: str
    latency_ms: int
    status_code: int | None = None
    payload: dict[str, Any] = field(default_factory=dict)
    error: str | None = None
    # TIMEOUT | RATE_LIMIT | AUTH | UNSUPPORTED | SERVER | HTTP | INVALID | NETWORK
    error_kind: str | None = None

    # Set when this response came from the demo adapter after a live call
    # failed. Without it the evidence reports the simulated reason — "network
    # timeout" — while the logs say the operator returned HTTP 500, and a
    # reviewer has no way to reconcile the two.
    fallback_from: str | None = None


class NacClient:
    """Thin async client over the Nokia Network as Code gateway."""

    def __init__(self, settings: Settings, client: httpx.AsyncClient | None = None) -> None:
        self.settings = settings
        self._client = client

    # ── plumbing ─────────────────────────────────────────────────────────

    def _headers(self, correlator: str) -> dict[str, str]:
        return {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "x-rapidapi-key": self.settings.nac_rapidapi_key,
            "x-rapidapi-host": self.settings.nac_rapidapi_host,
            # x-correlator is CAMARA's correlation header, and every one of
            # these APIs documents it. Sending our own means the id on a piece
            # of evidence can be handed to the operator to trace a single call
            # — which is the difference between provenance and a timestamp.
            "x-correlator": correlator,
        }

    async def _post(self, path: str, body: dict[str, Any], api: str) -> NacResponse:
        request_id = f"req_{uuid.uuid4().hex[:10]}"
        started = time.perf_counter()

        def elapsed() -> int:
            return int((time.perf_counter() - started) * 1000)

        if not self.settings.has_live_credentials:
            return NacResponse(
                ok=False,
                api=api,
                request_id=request_id,
                latency_ms=elapsed(),
                error="No Network as Code credentials configured.",
                error_kind="AUTH",
            )

        client = self._client or httpx.AsyncClient(
            base_url=self.settings.nac_base_url,
            timeout=self.settings.nac_timeout,
        )
        owns_client = self._client is None

        try:
            attempts = max(1, self.settings.nac_max_retries + 1)
            last: NacResponse | None = None

            for attempt in range(attempts):
                try:
                    response = await client.post(path, json=body, headers=self._headers(request_id))
                except httpx.TimeoutException as exc:
                    last = NacResponse(
                        ok=False, api=api, request_id=request_id, latency_ms=elapsed(),
                        error=f"Request timed out after {self.settings.nac_timeout}s: {exc}",
                        error_kind="TIMEOUT",
                    )
                    continue
                except httpx.HTTPError as exc:
                    last = NacResponse(
                        ok=False, api=api, request_id=request_id, latency_ms=elapsed(),
                        error=str(exc), error_kind="NETWORK",
                    )
                    continue

                # Prefer the provider's own correlator when it echoes one back;
                # that is the id their support team can actually look up.
                server_id = (
                    response.headers.get("x-correlator")
                    or response.headers.get("x-request-id")
                    or response.headers.get("x-correlation-id")
                    or request_id
                )

                if response.status_code == 429:
                    # Respect the limit rather than hammering it.
                    return NacResponse(
                        ok=False, api=api, request_id=server_id, latency_ms=elapsed(),
                        status_code=429, error="Rate limited by the network API.",
                        error_kind="RATE_LIMIT",
                    )

                if response.status_code in (401, 403):
                    return NacResponse(
                        ok=False, api=api, request_id=server_id, latency_ms=elapsed(),
                        status_code=response.status_code,
                        error="Authentication with the network API failed.",
                        error_kind="AUTH",
                    )

                if response.status_code >= 400:
                    # A 4xx is the operator telling us this request cannot be
                    # answered — usually a device it does not know. That is a
                    # provisioning problem for the ops team to fix, not an
                    # outage to wait out, and calling both "unavailable" hides
                    # the difference from whoever has to act on it.
                    kind = (
                        "UNSUPPORTED"
                        if response.status_code in (400, 404, 422)
                        else "SERVER" if response.status_code >= 500 else "HTTP"
                    )

                    return NacResponse(
                        ok=False, api=api, request_id=server_id, latency_ms=elapsed(),
                        status_code=response.status_code,
                        error=f"Network API returned HTTP {response.status_code}.",
                        error_kind=kind,
                        payload=_safe_json(response),
                    )

                payload = _safe_json(response)

                if not isinstance(payload, dict) or not payload:
                    return NacResponse(
                        ok=False, api=api, request_id=server_id, latency_ms=elapsed(),
                        status_code=response.status_code,
                        error="Network API returned an unreadable payload.",
                        error_kind="INVALID",
                    )

                return NacResponse(
                    ok=True, api=api, request_id=server_id, latency_ms=elapsed(),
                    status_code=response.status_code, payload=payload,
                )

            return last or NacResponse(
                ok=False, api=api, request_id=request_id, latency_ms=elapsed(),
                error="Network API unreachable.", error_kind="NETWORK",
            )
        finally:
            if owns_client:
                await client.aclose()

    # ── CAMARA capabilities ──────────────────────────────────────────────

    async def verify_location(
        self,
        device: dict[str, Any],
        latitude: float,
        longitude: float,
        radius_m: int,
        max_age_seconds: int,
    ) -> NacResponse:
        """Location Verification — is the device inside the expected circle?"""
        body = {
            "device": device,
            "area": {
                "areaType": "CIRCLE",
                "center": {"latitude": latitude, "longitude": longitude},
                "radius": radius_m,
            },
            "maxAge": max_age_seconds,
        }
        return await self._post(
            self.settings.nac_path_location_verification, body, "Location Verification"
        )

    async def device_status(self, device: dict[str, Any]) -> NacResponse:
        """Device Status — connectivity of the device on the network."""
        return await self._post(
            self.settings.nac_path_device_status, {"device": device}, "Device Status"
        )

    async def device_reachability(self, device: dict[str, Any]) -> NacResponse:
        """Device Reachability Status.

        Some deployments expose reachability through its own CAMARA API and
        some only through Device Status. We try the dedicated endpoint and
        let the caller fall back — an unavailable capability is reported
        honestly rather than silently substituted.
        """
        return await self._post(
            self.settings.nac_path_device_reachability,
            {"device": device},
            "Device Reachability Status",
        )

    async def device_roaming(self, device: dict[str, Any]) -> NacResponse:
        return await self._post(
            self.settings.nac_path_device_roaming, {"device": device}, "Device Roaming Status"
        )

    async def location_retrieval(self, device: dict[str, Any], max_age_seconds: int) -> NacResponse:
        return await self._post(
            self.settings.nac_path_location_retrieval,
            {"device": device, "maxAge": max_age_seconds},
            "Location Retrieval",
        )


def _safe_json(response: httpx.Response) -> dict[str, Any]:
    try:
        data = response.json()
        return data if isinstance(data, dict) else {"value": data}
    except Exception:  # noqa: BLE001 - any parse failure is the same failure
        return {}
