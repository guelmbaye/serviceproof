"""LLM provider abstraction.

The model layer is deliberately swappable: the orchestration semantics are
identical whether the planner is Gemini, Groq, a local Ollama model, or the
built-in deterministic planner. Nothing about the evidence contract or the
policy layer depends on which one is configured.
"""

from __future__ import annotations

import json
import logging
import re
from abc import ABC, abstractmethod
from typing import Any

logger = logging.getLogger(__name__)

_FENCE = re.compile(r"```(?:json)?\s*(.*?)\s*```", re.DOTALL)


class LlmClient(ABC):
    provider: str
    model: str

    @abstractmethod
    async def complete_json(self, system: str, user: str) -> dict[str, Any] | None:
        """Return a parsed JSON object, or None if the call failed."""

    @staticmethod
    def parse_json(text: str | None) -> dict[str, Any] | None:
        if not text:
            return None

        candidate = text.strip()
        match = _FENCE.search(candidate)
        if match:
            candidate = match.group(1).strip()

        # Some models still prepend prose; take the first balanced object.
        start = candidate.find("{")
        end = candidate.rfind("}")
        if start == -1 or end == -1 or end < start:
            return None

        try:
            parsed = json.loads(candidate[start : end + 1])
        except json.JSONDecodeError:
            logger.warning("Planner returned unparseable JSON; falling back to the deterministic planner.")
            return None

        return parsed if isinstance(parsed, dict) else None


class NullLlmClient(LlmClient):
    """No external model. The deterministic planner does all the work."""

    provider = "heuristic"
    model = "deterministic"

    async def complete_json(self, system: str, user: str) -> dict[str, Any] | None:
        return None
