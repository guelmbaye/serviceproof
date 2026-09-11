"""Wire contracts between Laravel (product core) and the agent runtime.

Laravel pre-loads everything the agent needs and ships it in one POST, so a
verification is a single bounded round trip with no callbacks.
"""

from __future__ import annotations

from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, Field

EvidenceTypeStr = Literal[
    "LOCATION_VERIFICATION",
    "DEVICE_SWAP",
    "DEVICE_STATUS",
    "DEVICE_REACHABILITY",
    "DEVICE_ROAMING_STATUS",
    "LOCATION_RETRIEVAL",
]

EvidenceStatusStr = Literal["SUPPORTED", "CONFLICTING", "UNAVAILABLE", "STALE", "INVALID"]
DecisionStateStr = Literal["VERIFIED", "PARTIAL", "DISPUTED", "UNVERIFIED"]


# ─────────────────────────── inbound ────────────────────────────────────


class RequestMeta(BaseModel):
    verification_run_id: str
    organization_id: str
    requested_at: datetime | None = None
    request_id: str | None = None


class ClaimIn(BaseModel):
    id: str
    reference: str
    type: str = "SERVICE_COMPLETED"
    claimed_at: datetime | None = None
    submitted_at: datetime | None = None
    # Free text written by a field worker. Treated as untrusted data, never
    # as an instruction to the agent.
    untrusted_notes: str | None = None
    app_reported_context: dict[str, Any] | None = None


class ExpectedSite(BaseModel):
    name: str
    latitude: float
    longitude: float
    radius_m: int = 1000


class ServiceWindow(BaseModel):
    starts_at: datetime | None = None
    ends_at: datetime | None = None


class WorkOrderIn(BaseModel):
    reference: str
    customer: str | None = None
    service_type: str | None = None
    risk_level: str = "NORMAL"
    scheduled_at: datetime | None = None
    window: ServiceWindow = Field(default_factory=ServiceWindow)
    expected_site: ExpectedSite


class WorkerIn(BaseModel):
    reference: str = "UNKNOWN"


class EntitlementIn(BaseModel):
    """Permission to ask the operator about this device.

    Knowing an MSISDN does not confer the right to query it. The product core
    resolves the binding between a worker, a device and the organisation, and
    states whether that binding currently authorises network queries. The agent
    enforces it rather than assuming it: an absent or inactive entitlement stops
    the run before any CAMARA call is made.

    Deliberately thin. Full consent management, per-jurisdiction lawful basis
    and revocation workflows are scoped and not built, and pretending otherwise
    would be the kind of overclaim this product exists to refuse.
    """

    status: str = "UNKNOWN"
    reference: str | None = None
    purpose: str | None = None
    granted_at: datetime | None = None
    expires_at: datetime | None = None

    # Which capabilities this entitlement covers. An empty list means the
    # binding was granted without naming any, which is not the same as
    # granting all of them — see permits_capability.
    allowed_capabilities: list[str] = Field(default_factory=list)

    @property
    def permits_network_query(self) -> bool:
        return self.status.upper() == "ACTIVE"

    def permits_capability(self, evidence_type: str) -> bool:
        """Per-capability permission, not a blanket one.

        An entitlement to ask where a device is does not extend to asking
        whether its SIM changed. Enforcing that distinction is the difference
        between a consent model and a checkbox, and it costs one comparison.

        An empty list is read as "nothing named, nothing permitted" rather than
        "unspecified, so allow" — the permissive reading is how scopes quietly
        become meaningless.
        """
        return (
            self.permits_network_query
            and evidence_type in self.allowed_capabilities
        )


class DeviceIn(BaseModel):
    reference: str
    identifier_type: str = "PHONE_NUMBER"
    identifier_key: str = "phoneNumber"
    identifier: str
    is_simulator: bool = True
    entitlement: EntitlementIn = Field(default_factory=EntitlementIn)

    def camara_device(self) -> dict[str, Any]:
        """The CAMARA `device` object."""
        return {self.identifier_key: self.identifier}


class PolicyIn(BaseModel):
    id: str | None = None
    key: str = "STANDARD_FIELD_SERVICE"
    name: str = "Standard field service"
    assurance_level: str = "STANDARD"
    required_evidence: list[str] = Field(default_factory=lambda: ["LOCATION_VERIFICATION"])
    optional_evidence: list[str] = Field(default_factory=list)
    max_tool_calls: int = 2
    max_latency_ms: int = 12000
    location_radius_m: int = 1000
    freshness_seconds: int = 900
    allow_partial: bool = True
    allowed_tools: list[str] = Field(default_factory=list)


class BudgetIn(BaseModel):
    max_tool_calls: int = 2
    max_latency_ms: int = 12000
    assurance_level: str = "STANDARD"


class DemoIn(BaseModel):
    force_mode: Literal["live", "demo"] | None = None
    scenario: Literal["VERIFIED", "DISPUTED", "UNVERIFIED"] | None = None


class VerifyRequest(BaseModel):
    request: RequestMeta
    claim: ClaimIn
    work_order: WorkOrderIn | None = None
    worker: WorkerIn = Field(default_factory=WorkerIn)
    device: DeviceIn | None = None
    policy: PolicyIn = Field(default_factory=PolicyIn)
    budget: BudgetIn = Field(default_factory=BudgetIn)
    tools: list[str] = Field(default_factory=list)
    demo: DemoIn = Field(default_factory=DemoIn)


# ─────────────────────────── outbound ───────────────────────────────────


class EvidenceOut(BaseModel):
    evidence_id: str
    type: EvidenceTypeStr
    status: EvidenceStatusStr
    source: Literal["CAMARA", "DEMO_FALLBACK"]
    provider: str | None = None
    api: str | None = None
    request_id: str | None = None
    observed_at: datetime | None = None
    received_at: datetime
    freshness: Literal["CURRENT", "STALE", "UNKNOWN"] = "UNKNOWN"
    age_seconds: int | None = None
    latency_ms: int | None = None
    reliability: float | None = None
    summary: str | None = None
    normalized: dict[str, Any] = Field(default_factory=dict)
    payload_hash: str | None = None
    raw_reference: dict[str, Any] | None = None
    failure_reason: str | None = None


class TraceOut(BaseModel):
    sequence: int
    event_type: str
    label: str
    detail: dict[str, Any] | None = None
    occurred_at: datetime


class EvidencePlanOut(BaseModel):
    minimum: list[str] = Field(default_factory=list)
    escalation: list[str] = Field(default_factory=list)
    rationale: str | None = None


class AssessmentOut(BaseModel):
    sufficient: bool = False
    supported: int = 0
    conflicting: int = 0
    unavailable: int = 0
    stale: int = 0
    missing_required: list[str] = Field(default_factory=list)
    assurance_score: int = 0
    breakdown: dict[str, Any] = Field(default_factory=dict)


class DecisionOut(BaseModel):
    state: DecisionStateStr
    recommended_action: str
    policy_satisfied: bool = False
    rationale: str | None = None


class PlannerInfo(BaseModel):
    mode: Literal["llm", "heuristic", "llm_fallback_heuristic"] = "heuristic"
    provider: str | None = None
    model: str | None = None


StopReasonStr = Literal[
    "EVIDENCE_SUFFICIENT",          # the policy was met; nothing more was worth spending
    "MATERIAL_CONFLICT_CONFIRMED",  # a conflict stood after corroboration
    "BUDGET_EXHAUSTED",             # the ceiling was reached before an answer
    "NO_CAPABILITY_AVAILABLE",      # no permitted tool remained
    "ENTITLEMENT_REFUSED",          # the run never reached the network
]


class VerifyResponse(BaseModel):
    status: Literal["COMPLETED", "FAILED"]
    agent_version: str
    planner: PlannerInfo
    evidence_plan: EvidencePlanOut | None = None
    evidence: list[EvidenceOut] = Field(default_factory=list)
    trace: list[TraceOut] = Field(default_factory=list)
    assessment: AssessmentOut | None = None
    decision: DecisionOut | None = None
    tool_calls_used: int = 0

    # Why the loop stopped, as a value rather than a sentence.
    #
    # The rationale explains the decision to a person; this explains the
    # agent's behaviour to a machine — and to a judge comparing two runs, an
    # objective label beats a paragraph. Run A stops EVIDENCE_SUFFICIENT with
    # budget left; Run B stops MATERIAL_CONFLICT_CONFIRMED, also with budget
    # left. Neither is BUDGET_EXHAUSTED, and that is the whole point.
    stop_reason: StopReasonStr = "EVIDENCE_SUFFICIENT"
    duration_ms: int = 0
    escalated: bool = False
    used_demo_fallback: bool = False
    failure_reason: str | None = None
