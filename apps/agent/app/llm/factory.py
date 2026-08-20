from __future__ import annotations

from app.config import Settings
from app.llm.base import LlmClient, NullLlmClient
from app.llm.gemini import GeminiClient
from app.llm.groq import GroqClient
from app.llm.ollama import OllamaClient


def build_llm(settings: Settings) -> LlmClient:
    """Resolve the configured planner, degrading safely to deterministic."""
    if not settings.llm_enabled:
        return NullLlmClient()

    if settings.llm_provider == "gemini":
        return GeminiClient(
            api_key=settings.gemini_api_key,
            model=settings.gemini_model,
            base_url=settings.gemini_base_url,
            timeout=settings.llm_timeout,
        )

    if settings.llm_provider == "groq":
        return GroqClient(
            api_key=settings.groq_api_key,
            model=settings.groq_model,
            base_url=settings.groq_base_url,
            timeout=settings.llm_timeout,
        )

    if settings.llm_provider == "ollama":
        return OllamaClient(
            base_url=settings.ollama_base_url,
            model=settings.ollama_model,
            timeout=settings.llm_timeout,
        )

    return NullLlmClient()
