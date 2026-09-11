"""Deterministic demo adapter.

Live API calls fail at the worst possible moment, so ServiceProof ships a
recorded fallback. Two rules make it honest:

  1. It produces payloads in the *same shape* as the real CAMARA responses,
     so the normaliser, the evidence contract and the decision path are
     identical to a live run — the architecture on show is the real one.
  2. Every item it produces is tagged DEMO_FALLBACK all the way to the UI.
     Simulated evidence is never presented as live network evidence.
"""

from __future__ import annotations

import hashlib
import math
import time
import uuid
from datetime import datetime, timedelta, timezone
from typing import Any

from app.camara.client import NacResponse

# Position the Nokia simulator actually reports for its test numbers.
#
# Observed, not assumed: a live Location Verification against 50.735851 /
# 7.10066 returns TRUE, and against 47.48628 / 19.07916 returns FALSE. An
# earlier value taken from the written guide put this in Budapest and was
# wrong — the demo adapter has to agree with the live network or the two
# modes tell different stories about the same work order.
SIMULATOR_HOME = (50.735851, 7.10066)

# Devices used to demonstrate the "network evidence unavailable" path.
UNREACHABLE_SUFFIXES = ("0400", "0404")


def _now() -> datetime:
    return datetime.now(timezone.utc)


def haversine_m(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Great-circle distance in metres."""
    radius = 6_371_000.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    d_phi = math.radians(lat2 - lat1)
    d_lambda = math.radians(lon2 - lon1)
    a = math.sin(d_phi / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(d_lambda / 2) ** 2
    return 2 * radius * math.asin(math.sqrt(a))


class DemoNetwork:
    """Reproducible simulated network behaviour."""

    def __init__(self, scenario: str | None = None) -> None:
        self.scenario = scenario

    # ── scenario resolution ──────────────────────────────────────────────

    def resolve(self, identifier: str, latitude: float, longitude: float, radius_m: int) -> str:
        """Pick a scenario deterministically.

        An explicit scenario always wins (that is how the demo script pins
        WO-1042 / WO-1043 / WO-1044). Otherwise the outcome is derived from
        real geometry: if the expected site contains the simulator's known
        position, location is consistent — exactly as a live call would be.
        """
        if self.scenario:
            return self.scenario

        if identifier and identifier.endswith(UNREACHABLE_SUFFIXES):
            return "UNVERIFIED"

        distance = haversine_m(*SIMULATOR_HOME, latitude, longitude)
        return "VERIFIED" if distance <= max(radius_m, 100) else "DISPUTED"

    # ── capabilities (payload-compatible with CAMARA) ────────────────────

    def verify_location(
        self, identifier: str, latitude: float, longitude: float, radius_m: int
    ) -> NacResponse:
        scenario = self.resolve(identifier, latitude, longitude, radius_m)

        if scenario == "UNVERIFIED":
            return self._failure("Location Verification", "Simulated network timeout.", "TIMEOUT")

        return self._success(
            "Location Verification",
            {
                "verificationResult": "TRUE" if scenario == "VERIFIED" else "FALSE",
                "lastLocationTime": self._observed(seconds_ago=self._jitter(identifier, 30, 240)),
            },
        )

    def device_swap(self, identifier: str, scenario: str | None = None) -> NacResponse:
        """Mirrors the documented simulator behaviour on the two control numbers.

        Nokia documents +99999991000 as the device-swap scenario and
        +99999991001 as the no-swap one — the same split as location, which is
        what makes the two demonstration runs coherent rather than coincidental.
        """
        if scenario == "UNVERIFIED":
            return self._failure("Device Swap", "Simulated API unavailable.", "HTTP")

        swapped = identifier.strip().endswith("1000")

        return self._success("Device Swap", {"swapped": swapped})

    def device_status(self, identifier: str, scenario: str) -> NacResponse:
        if scenario == "UNVERIFIED":
            return self._failure("Device Status", "Simulated API unavailable.", "HTTP")

        return self._success("Device Status", {"connectivityStatus": "CONNECTED_DATA"})

    def device_reachability(self, identifier: str, scenario: str) -> NacResponse:
        if scenario == "UNVERIFIED":
            return self._failure("Device Reachability Status", "Simulated API unavailable.", "HTTP")

        return self._success(
            "Device Reachability Status", {"reachabilityStatus": "CONNECTED_DATA"}
        )

    def device_roaming(self, identifier: str, scenario: str) -> NacResponse:
        return self._success("Device Roaming Status", {"roaming": False, "countryCode": 212})

    def location_retrieval(self, identifier: str) -> NacResponse:
        lat, lon = SIMULATOR_HOME
        return self._success(
            "Location Retrieval",
            {
                "lastLocationTime": self._observed(seconds_ago=self._jitter(identifier, 30, 240)),
                "area": {
                    "areaType": "CIRCLE",
                    "center": {"latitude": lat, "longitude": lon},
                    "radius": 1000,
                },
            },
        )

    # ── helpers ──────────────────────────────────────────────────────────

    def _success(self, api: str, payload: dict[str, Any]) -> NacResponse:
        return NacResponse(
            ok=True,
            api=api,
            request_id=f"demo_{uuid.uuid4().hex[:10]}",
            latency_ms=int(40 + (time.perf_counter() * 1000) % 60),
            status_code=200,
            payload=payload,
        )

    def _failure(self, api: str, message: str, kind: str) -> NacResponse:
        return NacResponse(
            ok=False,
            api=api,
            request_id=f"demo_{uuid.uuid4().hex[:10]}",
            latency_ms=120,
            error=message,
            error_kind=kind,
        )

    def _observed(self, seconds_ago: int) -> str:
        return (_now() - timedelta(seconds=seconds_ago)).isoformat().replace("+00:00", "Z")

    @staticmethod
    def _jitter(seed: str, low: int, high: int) -> int:
        """Stable pseudo-random age so repeated demos look alike."""
        digest = hashlib.sha256((seed or "seed").encode()).digest()
        return low + (digest[0] % max(1, high - low))
