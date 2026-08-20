<?php

namespace App\Services\Agent;

use App\Domain\Shared\Enums\EvidenceStatus;
use App\Domain\Shared\Enums\EvidenceType;
use Illuminate\Support\Str;

/**
 * Deterministic in-process stand-in used by the test-suite (AGENT_FAKE=true).
 *
 * It reproduces the real contract, including escalation, so feature tests
 * exercise the same persistence and guard paths as production.
 */
class FakeAgentClient implements AgentGateway
{
    public function verify(array $bundle): AgentResult
    {
        $scenario = $bundle['demo']['scenario'] ?? 'VERIFIED';
        $sequence = 0;
        $trace = [];
        $evidence = [];

        $addTrace = function (string $type, string $label, array $detail = []) use (&$sequence, &$trace) {
            $trace[] = [
                'sequence' => ++$sequence,
                'event_type' => $type,
                'label' => $label,
                'detail' => $detail,
                'occurred_at' => now()->toIso8601String(),
            ];
        };

        $addEvidence = function (EvidenceType $type, EvidenceStatus $status, string $summary) use (&$evidence) {
            $evidence[] = [
                'evidence_id' => 'ev_'.Str::random(10),
                'type' => $type->value,
                'status' => $status->value,
                'source' => 'DEMO_FALLBACK',
                'provider' => 'FAKE',
                'api' => $type->apiName(),
                'request_id' => 'req_'.Str::random(6),
                'observed_at' => now()->toIso8601String(),
                'received_at' => now()->toIso8601String(),
                'freshness' => 'CURRENT',
                'age_seconds' => 12,
                'latency_ms' => 120,
                'reliability' => 0.9,
                'summary' => $summary,
                'normalized' => [],
            ];
        };

        $addTrace('AGENT_STARTED', 'Claim received');
        $addTrace('PLAN_CREATED', 'Evidence plan created');
        $addTrace('TOOL_SELECTED', 'Location Verification selected');

        if ($scenario === 'UNVERIFIED') {
            $addEvidence(EvidenceType::LOCATION_VERIFICATION, EvidenceStatus::UNAVAILABLE, 'Network evidence temporarily unavailable.');
            $addTrace('EVIDENCE_UNAVAILABLE', 'Location evidence unavailable');
            $decision = ['state' => 'UNVERIFIED', 'recommended_action' => 'MANUAL_VERIFICATION', 'policy_satisfied' => false, 'rationale' => 'No usable evidence.'];
        } elseif ($scenario === 'DISPUTED') {
            $addEvidence(EvidenceType::LOCATION_VERIFICATION, EvidenceStatus::CONFLICTING, 'Device not consistent with the expected site.');
            $addTrace('EVIDENCE_CONFLICT', 'Location conflict detected');
            $addTrace('ESCALATION_REQUIRED', 'Additional evidence required');
            $addEvidence(EvidenceType::DEVICE_STATUS, EvidenceStatus::SUPPORTED, 'Device active on the network.');
            $addEvidence(EvidenceType::DEVICE_REACHABILITY, EvidenceStatus::SUPPORTED, 'Device reachable.');
            $decision = ['state' => 'DISPUTED', 'recommended_action' => 'ESCALATE', 'policy_satisfied' => false, 'rationale' => 'Evidence remains conflicting.'];
        } else {
            $addEvidence(EvidenceType::LOCATION_VERIFICATION, EvidenceStatus::SUPPORTED, 'Device consistent with the expected site.');
            $decision = ['state' => 'VERIFIED', 'recommended_action' => 'CLOSE', 'policy_satisfied' => true, 'rationale' => 'Policy satisfied by location evidence.'];
        }

        $addTrace('POLICY_EVALUATED', 'Policy evaluated');
        $addTrace('DECISION_PROPOSED', 'Decision: '.$decision['state']);
        $addTrace('AGENT_COMPLETED', 'Verification completed');

        return AgentResult::fromResponse([
            'status' => 'COMPLETED',
            'agent_version' => 'fake-1.0.0',
            'planner' => ['mode' => 'heuristic', 'provider' => 'fake', 'model' => null],
            'evidence' => $evidence,
            'trace' => $trace,
            'decision' => $decision,
            'assessment' => ['assurance_score' => $decision['state'] === 'VERIFIED' ? 92 : 40],
            'evidence_plan' => ['minimum' => ['LOCATION_VERIFICATION'], 'escalation' => ['DEVICE_STATUS', 'DEVICE_REACHABILITY']],
            'tool_calls_used' => count($evidence),
            'duration_ms' => 42,
            'escalated' => count($evidence) > 1,
            'used_demo_fallback' => true,
        ]);
    }

    public function health(): array
    {
        return ['reachable' => true, 'status' => 200, 'detail' => ['mode' => 'fake']];
    }
}
