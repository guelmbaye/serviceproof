"""ServiceProof AI — Agent Runtime.

    Laravel owns the product.
    FastAPI owns the agent.
    CAMARA provides the network evidence.
    PostgreSQL owns the truth.

This process holds no durable state. It plans evidence, calls network
capabilities through a controlled tool layer, evaluates what came back
against the supplied policy, and hands a proposal back to the product core.
"""

from __future__ import annotations

import logging

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.api.routes import health, verify
from app.config import AGENT_VERSION, get_settings

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
)

settings = get_settings()

app = FastAPI(
    title="ServiceProof AI — Agent Runtime",
    description=(
        "Evidence orchestration for network-powered operational assurance. "
        "Internal service: reachable only from the ServiceProof product core."
    ),
    version=AGENT_VERSION,
    docs_url="/docs" if settings.sp_env != "production" else None,
    redoc_url=None,
)

# No browser origin is expected to talk to this service. CORS stays closed.
app.add_middleware(
    CORSMiddleware,
    allow_origins=[],
    allow_methods=["POST", "GET"],
    allow_headers=["*"],
)

app.include_router(health.router)
app.include_router(verify.router)


@app.get("/", include_in_schema=False)
async def root() -> dict:
    return {
        "service": "serviceproof-agent",
        "version": AGENT_VERSION,
        "principle": "The AI decides what to investigate. The network provides the evidence. Policy decides what is sufficient.",
    }
