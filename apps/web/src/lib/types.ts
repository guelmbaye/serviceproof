/**
 * Mirrors the Laravel API resources. Kept hand-written rather than generated:
 * the shapes are stable, and an explicit type is documentation the next
 * person can read without running a codegen step.
 */

export type DecisionState = "VERIFIED" | "PARTIAL" | "DISPUTED" | "UNVERIFIED";
export type EvidenceStatus = "SUPPORTED" | "CONFLICTING" | "UNAVAILABLE" | "STALE" | "INVALID";
export type Role =
  | "SUPER_ADMIN"
  | "ORG_ADMIN"
  | "OPERATIONS_MANAGER"
  | "REVIEWER"
  | "FIELD_WORKER";

export interface SessionUser {
  id: string;
  name: string;
  email: string;
  role: Role;
  status: string;
  employee_reference: string | null;
  organization?: { id: string; name: string; default_policy_key: string | null };
  capabilities: {
    back_office: boolean;
    can_review: boolean;
    can_administer: boolean;
    can_trigger_verification: boolean;
  };
  last_login_at: string | null;
}

export interface Decision {
  id: string;
  state: DecisionState;
  recommended_action: string;
  recommended_action_label: string;
  requires_review: boolean;
  assurance: {
    score: number | null;
    breakdown: AssuranceBreakdown | null;
  };
  rationale: string | null;
  origin: "AGENT" | "HUMAN" | "SYSTEM" | string;
  policy_satisfied: boolean;
  guard: { applied: boolean; reason: string | null };
  simulated: boolean;
  is_current: boolean;
  superseded_by: string | null;
  created_at: string | null;
}

export interface AssuranceBreakdown {
  score?: number;
  components?: Record<string, number>;
  weights?: Record<string, number>;
  counts?: Record<string, number>;
  provenance?: string;
}

export interface Provenance {
  source: "CAMARA" | "DEMO_FALLBACK" | string;
  source_label: string;
  provider: string | null;
  api: string | null;
  request_id: string | null;
  observed_at: string | null;
  received_at: string | null;
  freshness: string | null;
  age_seconds: number | null;
  latency_ms: number | null;
}

export interface Evidence {
  id: string;
  type: string;
  api: string | null;
  business_question: string;
  status: EvidenceStatus;
  usable: boolean;
  summary: string | null;
  reliability: number | null;
  simulated: boolean;
  provenance: Provenance;
  normalized: Record<string, unknown> | null;
  failure_reason: string | null;
}

export interface TraceEvent {
  sequence: number;
  event_type: string;
  label: string;
  detail: Record<string, unknown> | null;
  occurred_at: string | null;
}

export interface VerificationRun {
  id: string;
  status: string;
  assurance_level: string;
  policy?: { key: string; name: string; required_evidence: string[] | null };
  budget: {
    max_tool_calls: number;
    tool_calls_used: number;
    remaining: number;
    max_latency_ms: number | null;
  };
  evidence_plan: { minimum?: string[]; escalation?: string[]; rationale?: string } | null;
  escalated: boolean;
  used_demo_fallback: boolean;
  agent: {
    version: string | null;
    planner_mode: string | null;
    provider: string | null;
    model: string | null;
  };
  duration_ms: number | null;
  failure_reason: string | null;
  started_at: string | null;
  completed_at: string | null;
  evidence?: Evidence[];
  trace?: TraceEvent[];
  decision?: Decision | null;
}

export interface Claim {
  id: string;
  reference: string;
  type: string;
  status: string;
  claimed_at: string | null;
  submitted_at: string | null;
  notes: string | null;
  work_order?: {
    id: string;
    reference: string;
    customer: string | null;
    site: string | null;
    status: string;
  };
  worker?: { reference: string | null; name: string };
  device?: { reference: string | null };
  decision?: Decision | null;
  verification?: VerificationRun | null;
  created_at: string | null;
}

export interface WorkOrder {
  id: string;
  reference: string;
  customer: string | null;
  service_type: string | null;
  description: string | null;
  site: {
    name: string | null;
    address: string | null;
    latitude: number | null;
    longitude: number | null;
    radius_m: number | null;
  };
  risk_level: string;
  status: string;
  scheduled_at: string | null;
  window: { starts_at: string | null; ends_at: string | null };
  assigned_to?: { id: string; name: string; reference: string | null } | null;
  device?: { reference: string | null };
  policy?: { key: string; name: string };
  claims?: Claim[];
  created_at: string | null;
}

export interface Review {
  id: string;
  status: string;
  reason: string | null;
  /** CONFIRMED (upheld) or OVERRIDDEN. */
  outcome: "CONFIRMED" | "OVERRIDDEN" | null;
  override_state: string | null;
  resolution_notes: string | null;
  assigned_to?: string | null;
  resolved_by?: string | null;
  resolved_at: string | null;
  claim?: Claim | null;
  decision?: Decision | null;
  created_at: string | null;
}

export interface Overview {
  metrics: {
    window: { since: string; until: string };
    claims: { total: number; decided: number };
    decisions: { verified: number; partial: number; disputed: number; unverified: number };
    automation: {
      auto_verification_rate: number | null;
      dispute_rate: number | null;
      escalation_rate: number | null;
      avg_tool_calls_per_run: number | null;
      avg_decision_ms: number;
    };
    reviews: { open: number; resolved: number };
    evidence: {
      total: number;
      simulated: number;
      by_status: Record<string, number>;
    };
  };
  recent_verifications: VerificationRun[];
  exceptions: Review[];
  recent_claims: Claim[];
}

/** GET /verifications/health — Laravel wraps the agent's own /health body. */
export interface AgentHealth {
  agent?: {
    reachable: boolean;
    /** HTTP status of the probe, not the agent's own status string. */
    status?: number | null;
    detail?: {
      status?: string;
      version?: string;
      planner?: { provider?: string; llm_enabled?: boolean };
      camara?: { mode?: string; live_credentials?: boolean; provider?: string };
      tools?: string[];
      error?: string;
    };
  };
  checked_at?: string;
}

export interface Paginated<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; total: number; per_page: number };
  links?: Record<string, string | null>;
}
