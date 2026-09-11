"""Tool registry.

The agent can only ever see tools that appear here *and* are permitted by
the organisation's policy. A model asking for anything else is refused —
it cannot invent an API operation.
"""

from __future__ import annotations

from app.tools.base import Tool
from app.tools.device_status import DeviceStatusTool
from app.tools.device_swap import DeviceSwapTool
from app.tools.location import VerifyLocationTool
from app.tools.reachability import ReachabilityTool

_ALL: dict[str, Tool] = {
    tool.name: tool
    for tool in (
        VerifyLocationTool(),
        DeviceSwapTool(),
        DeviceStatusTool(),
        ReachabilityTool(),
    )
}


def available_tools(allowed: list[str] | None = None) -> dict[str, Tool]:
    if not allowed:
        return dict(_ALL)
    return {name: tool for name, tool in _ALL.items() if name in allowed}


def get_tool(name: str) -> Tool | None:
    return _ALL.get(name)


def tool_for_evidence_type(evidence_type: str) -> Tool | None:
    for tool in _ALL.values():
        if tool.evidence_type == evidence_type:
            return tool
    return None


def specs(allowed: list[str] | None = None) -> list[dict]:
    return [tool.spec() for tool in available_tools(allowed).values()]
