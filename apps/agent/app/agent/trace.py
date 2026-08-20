"""Observable decision trace.

What this records: which tool was selected, what came back, which policy
was applied, what was decided. What it deliberately does not record: the
model's private reasoning. The UI shows an action trace, not chain-of-thought.
"""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

from app.schemas import TraceOut


class TraceRecorder:
    def __init__(self) -> None:
        self._events: list[TraceOut] = []
        self._sequence = 0

    def add(self, event_type: str, label: str, detail: dict[str, Any] | None = None) -> TraceOut:
        self._sequence += 1
        event = TraceOut(
            sequence=self._sequence,
            event_type=event_type,
            label=label,
            detail=detail or None,
            occurred_at=datetime.now(timezone.utc),
        )
        self._events.append(event)
        return event

    @property
    def events(self) -> list[TraceOut]:
        return list(self._events)
