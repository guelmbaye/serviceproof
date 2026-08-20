from __future__ import annotations

import logging
from typing import Any

import httpx

from app.llm.base import LlmClient

logger = logging.getLogger(__name__)


class OllamaClient(LlmClient):
    """Fully local planner. Zero cost, no key, no data leaving the machine."""

    provider = "ollama"

    def __init__(self, base_url: str, model: str, timeout: float) -> None:
        self.base_url = base_url.rstrip("/")
        self.model = model
        self.timeout = timeout

    async def complete_json(self, system: str, user: str) -> dict[str, Any] | None:
        body = {
            "model": self.model,
            "format": "json",
            "stream": False,
            "options": {"temperature": 0.1},
            "messages": [
                {"role": "system", "content": system},
                {"role": "user", "content": user},
            ],
        }

        try:
            async with httpx.AsyncClient(timeout=self.timeout) as client:
                response = await client.post(f"{self.base_url}/api/chat", json=body)
                response.raise_for_status()
                data = response.json()
        except Exception as exc:  # noqa: BLE001
            logger.warning("Ollama planner unavailable: %s", exc)
            return None

        return self.parse_json((data.get("message") or {}).get("content"))
