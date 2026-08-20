<?php

namespace App\Services\Agent;

/**
 * Normalised view of the agent runtime response.
 *
 * Nothing in here is trusted as a business decision on its own: the
 * DecisionGuard re-derives the final state from the evidence.
 */
class AgentResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly array $evidence = [],
        public readonly array $trace = [],
        public readonly ?array $decision = null,
        public readonly ?array $assessment = null,
        public readonly ?array $evidencePlan = null,
        public readonly int $toolCallsUsed = 0,
        public readonly int $durationMs = 0,
        public readonly bool $escalated = false,
        public readonly bool $usedDemoFallback = false,
        public readonly ?string $agentVersion = null,
        public readonly ?string $plannerMode = null,
        public readonly ?string $llmProvider = null,
        public readonly ?string $llmModel = null,
        public readonly ?string $failureReason = null,
    ) {}

    public static function fromResponse(array $payload): self
    {
        $planner = $payload['planner'] ?? [];

        return new self(
            ok: ($payload['status'] ?? 'FAILED') !== 'FAILED',
            status: $payload['status'] ?? 'FAILED',
            evidence: $payload['evidence'] ?? [],
            trace: $payload['trace'] ?? [],
            decision: $payload['decision'] ?? null,
            assessment: $payload['assessment'] ?? null,
            evidencePlan: $payload['evidence_plan'] ?? null,
            toolCallsUsed: (int) ($payload['tool_calls_used'] ?? 0),
            durationMs: (int) ($payload['duration_ms'] ?? 0),
            escalated: (bool) ($payload['escalated'] ?? false),
            usedDemoFallback: (bool) ($payload['used_demo_fallback'] ?? false),
            agentVersion: $payload['agent_version'] ?? null,
            plannerMode: $planner['mode'] ?? null,
            llmProvider: $planner['provider'] ?? null,
            llmModel: $planner['model'] ?? null,
            failureReason: $payload['failure_reason'] ?? null,
        );
    }

    /** The agent runtime is unreachable or errored: fail closed, never fabricate. */
    public static function failed(string $reason, array $trace = []): self
    {
        return new self(
            ok: false,
            status: 'FAILED',
            trace: $trace,
            failureReason: $reason,
        );
    }
}
