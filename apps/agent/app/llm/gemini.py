from __future__ import annotations

import logging
from typing import Any

import httpx

from app.llm.base import LlmClient

logger = logging.getLogger(__name__)


class GeminiClient(LlmClient):
    provider = "gemini"

    def __init__(self, api_key: str, model: str, base_url: str, timeout: float) -> None:
        self.api_key = api_key
        self.model = model
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout

    async def complete_json(self, system: str, user: str) -> dict[str, Any] | None:
        url = f"{self.base_url}/models/{self.model}:generateContent"
        body = {
            "system_instruction": {"parts": [{"text": system}]},
            "contents": [{"role": "user", "parts": [{"text": user}]}],
            "generationConfig": {
                "temperature": 0.1,
                "maxOutputTokens": 512,
                "responseMimeType": "application/json",
            },
        }

        try:
            async with httpx.AsyncClient(timeout=self.timeout) as client:
                response = await client.post(url, json=body, params={"key": self.api_key})
                response.raise_for_status()
                data = response.json()
        except Exception as exc:  # noqa: BLE001
            logger.warning("Gemini planner unavailable: %s", exc)
            return None

        try:
            text = data["candidates"][0]["content"]["parts"][0]["text"]
        except (KeyError, IndexError, TypeError):
            return None

        return self.parse_json(text)
