from __future__ import annotations

import logging
from typing import Any

import httpx

from app.llm.base import LlmClient

logger = logging.getLogger(__name__)


class GroqClient(LlmClient):
    """OpenAI-compatible chat completions. Fast enough for a live demo."""

    provider = "groq"

    def __init__(self, api_key: str, model: str, base_url: str, timeout: float) -> None:
        self.api_key = api_key
        self.model = model
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout

    async def complete_json(self, system: str, user: str) -> dict[str, Any] | None:
        body = {
            "model": self.model,
            "temperature": 0.1,
            "max_tokens": 512,
            "response_format": {"type": "json_object"},
            "messages": [
                {"role": "system", "content": system},
                {"role": "user", "content": user},
            ],
        }

        try:
            async with httpx.AsyncClient(timeout=self.timeout) as client:
                response = await client.post(
                    f"{self.base_url}/chat/completions",
                    json=body,
                    headers={"Authorization": f"Bearer {self.api_key}"},
                )
                response.raise_for_status()
                data = response.json()
        except Exception as exc:  # noqa: BLE001
            logger.warning("Groq planner unavailable: %s", exc)
            return None

        try:
            text = data["choices"][0]["message"]["content"]
        except (KeyError, IndexError, TypeError):
            return None

        return self.parse_json(text)
