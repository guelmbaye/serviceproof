"""Internal-channel authentication.

The agent runtime is not a public service. Only the Laravel product core
may call it, using a shared secret that never leaves the backend network.
"""

from __future__ import annotations

import secrets

from fastapi import Header, HTTPException, status

from app.config import get_settings


async def require_internal_token(x_internal_token: str = Header(default="")) -> None:
    expected = get_settings().agent_internal_token

    if not expected or not secrets.compare_digest(x_internal_token, expected):
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Invalid internal token.",
        )
