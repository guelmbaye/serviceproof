<?php

namespace App\Services\Agent;

use App\Domain\Claims\Models\Claim;
use App\Domain\Verification\Models\VerificationRun;

/**
 * Builds the context bundle handed to the agent runtime.
 *
 * Two principles drive the shape of this payload:
 *
 *  1. Pre-load everything. Laravel reads the claim, work order, policy and
 *     device mapping from PostgreSQL once and ships them in the initial POST.
 *     The agent never needs a callback, so the whole cycle stays inside a
 *     single, bounded HTTP request.
 *
 *  2. Minimise. The AI layer receives only what it needs to plan evidence:
 *     no employee names, no customer contact details, no unrelated records.
 */
class AgentContextBuilder
{
    public function build(VerificationRun $run, Claim $claim, array $options = []): array
    {
        $workOrder = $claim->workOrder;
        $policy = $run->policy;
        $device = $claim->device ?? $workOrder?->device;

        return [
            'request' => [
                'verification_run_id' => $run->id,
                'organization_id' => $run->organization_id,
                'requested_at' => now()->toIso8601String(),
                'request_id' => request()->attributes->get('request_id'),
            ],

            'claim' => [
                'id' => $claim->id,
                'reference' => $claim->reference,
                'type' => $claim->claim_type,
                'claimed_at' => $claim->claimed_at?->toIso8601String(),
                'submitted_at' => $claim->submitted_at?->toIso8601String(),
                // Explicitly labelled: worker-supplied free text is untrusted
                // input, never an instruction to the agent.
                'untrusted_notes' => $claim->untrustedNotes(),
                'app_reported_context' => $claim->context,
            ],

            'work_order' => $workOrder ? [
                'reference' => $workOrder->reference,
                'customer' => $workOrder->customer_name,
                'service_type' => $workOrder->service_type,
                'risk_level' => $workOrder->risk_level,
                'scheduled_at' => $workOrder->scheduled_at?->toIso8601String(),
                'window' => [
                    'starts_at' => $workOrder->window_starts_at?->toIso8601String(),
                    'ends_at' => $workOrder->window_ends_at?->toIso8601String(),
                ],
                'expected_site' => $workOrder->expectedArea(),
            ] : null,

            // Pseudonymous worker reference only — no name, no contact details.
            'worker' => [
                'reference' => $claim->user?->employee_reference ?? 'UNKNOWN',
            ],

            'device' => $device?->toNetworkDescriptor(),

            'policy' => $policy?->toAgentPayload(),

            'budget' => [
                'max_tool_calls' => (int) $run->budget_max_tool_calls,
                'max_latency_ms' => (int) $run->budget_max_latency_ms,
                'assurance_level' => $run->assurance_level->value,
            ],

            'tools' => $policy?->allowedTools() ?? config('serviceproof.enabled_tools', []),

            'demo' => [
                // Forces the evidence adapter. Null = normal behaviour
                // (live first, clearly-labelled fallback on failure).
                'force_mode' => $options['force_mode'] ?? null,
                'scenario' => $options['scenario'] ?? null,
            ],
        ];
    }
}
