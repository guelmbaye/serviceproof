"""Runtime configuration for the ServiceProof AI agent.

Everything that can differ between a laptop, the hackathon venue Wi-Fi and a
judging room is an environment variable. Nothing here is baked into code.
"""

from functools import lru_cache
from typing import Literal

from pydantic_settings import BaseSettings, SettingsConfigDict

AGENT_VERSION = "1.0.0"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # ── Runtime ──────────────────────────────────────────────────────────
    sp_env: str = "local"
    agent_internal_token: str = "dev-internal-token"
    laravel_base_url: str = "http://api:8000"

    # ── LLM provider ─────────────────────────────────────────────────────
    # "heuristic" runs the deterministic planner only: same orchestration
    # semantics, zero external AI dependency. It is the demo-day safety net.
    llm_provider: Literal["heuristic", "gemini", "groq", "ollama"] = "heuristic"
    llm_timeout: float = 12.0

    gemini_api_key: str = ""
    gemini_model: str = "gemini-2.5-flash"
    gemini_base_url: str = "https://generativelanguage.googleapis.com/v1beta"

    groq_api_key: str = ""
    groq_model: str = "llama-3.3-70b-versatile"
    groq_base_url: str = "https://api.groq.com/openai/v1"

    ollama_base_url: str = "http://host.docker.internal:11434"
    ollama_model: str = "llama3.1"

    # ── Nokia Network as Code (CAMARA access layer) ──────────────────────
    # auto : try live, fall back to the clearly-labelled demo adapter
    # live : live only — failures surface honestly as UNAVAILABLE evidence
    # demo : demo adapter only — every item tagged DEMO_FALLBACK
    camara_mode: Literal["auto", "live", "demo"] = "auto"
    # The gateway host, taken from the Nokia developer portal snippets. Note
    # that it is an apihub.nokia.io host, not a rapidapi.com one — the key and
    # the x-rapidapi-host header are RapidAPI's, the endpoint is Nokia's.
    nac_base_url: str = "https://network-as-code.p-eu.apihub.nokia.io"
    nac_rapidapi_key: str = ""
    nac_rapidapi_host: str = "network-as-code.nokia.rapidapi.com"
    nac_timeout: float = 8.0
    nac_max_retries: int = 1

    # Capability paths, confirmed against the portal. Each carries its own
    # CAMARA API version, so they are configuration rather than constants —
    # location-verification is on v1 while location-retrieval is still v0, and
    # they will not move in step.
    nac_path_location_verification: str = "/location-verification/v1/verify"
    nac_path_location_retrieval: str = "/location-retrieval/v0/retrieve"
    nac_path_device_status: str = "/device-status/v0/connectivity"

    # Roaming exists twice: the older Device Status v0.5.1 operation and the
    # dedicated Device Roaming Status v1.1.0 API. The v1 endpoint is the one
    # to build on; the v0 path is kept in a comment for a fallback.
    #   v0 alternative: /device-status/v0/roaming
    nac_path_device_roaming: str = "/device-status/device-roaming-status/v1/retrieve"
    nac_path_device_reachability: str = (
        "/device-status/device-reachability-status/v1/retrieve"
    )

    # ── Evidence semantics ───────────────────────────────────────────────
    default_max_age_seconds: int = 900
    # Beyond this gap between the claim time and the observation, a device
    # signal is corroborating context rather than contemporaneous evidence.
    contemporaneity_window_seconds: int = 1800

    @property
    def has_live_credentials(self) -> bool:
        return bool(self.nac_rapidapi_key)

    @property
    def llm_enabled(self) -> bool:
        if self.llm_provider == "heuristic":
            return False
        if self.llm_provider == "gemini":
            return bool(self.gemini_api_key)
        if self.llm_provider == "groq":
            return bool(self.groq_api_key)
        return True  # ollama needs no key


@lru_cache
def get_settings() -> Settings:
    return Settings()
