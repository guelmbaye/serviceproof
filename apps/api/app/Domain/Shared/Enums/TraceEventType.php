<?php

namespace App\Domain\Shared\Enums;

enum TraceEventType: string
{
    /**
     * Laravel opened a run. Distinct from AGENT_STARTED, which the agent
     * emits when it receives the bundle: one is the product core deciding to
     * verify, the other is the runtime beginning to. Collapsing them into one
     * name is what produced a trace that opened with "Claim received" twice.
     */
    case VERIFICATION_REQUESTED = 'VERIFICATION_REQUESTED';

    case AGENT_STARTED = 'AGENT_STARTED';
    case CONTEXT_LOADED = 'CONTEXT_LOADED';
    case PLAN_CREATED = 'PLAN_CREATED';
    case TOOL_SELECTED = 'TOOL_SELECTED';
    case TOOL_CALLED = 'TOOL_CALLED';
    case EVIDENCE_RECEIVED = 'EVIDENCE_RECEIVED';
    case EVIDENCE_CONFLICT = 'EVIDENCE_CONFLICT';
    case EVIDENCE_UNAVAILABLE = 'EVIDENCE_UNAVAILABLE';
    case ESCALATION_REQUIRED = 'ESCALATION_REQUIRED';
    case BUDGET_EXHAUSTED = 'BUDGET_EXHAUSTED';
    case POLICY_EVALUATED = 'POLICY_EVALUATED';
    case DECISION_PROPOSED = 'DECISION_PROPOSED';
    case GUARD_APPLIED = 'GUARD_APPLIED';
    case AGENT_COMPLETED = 'AGENT_COMPLETED';
    case AGENT_FAILED = 'AGENT_FAILED';
}
