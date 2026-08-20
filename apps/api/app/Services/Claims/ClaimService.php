<?php

namespace App\Services\Claims;

use App\Domain\Claims\Models\Claim;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AuditEventType;
use App\Domain\Shared\Enums\ClaimStatus;
use App\Domain\Shared\Enums\WorkOrderStatus;
use App\Domain\WorkOrders\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClaimService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Submit a service-completion claim.
     *
     * The claim is an assertion. It carries no evidential weight and never
     * sets a verification outcome by itself — a client cannot post
     * "location = verified" and have it believed.
     */
    public function submit(WorkOrder $workOrder, User $worker, array $data): Claim
    {
        if (! $workOrder->status->acceptsClaim()) {
            throw new RuntimeException("Work order {$workOrder->reference} is {$workOrder->status->value} and no longer accepts claims.");
        }

        return DB::transaction(function () use ($workOrder, $worker, $data) {
            // Offline-first mobile clients retry; the idempotency key makes
            // a retry a no-op instead of a duplicate claim.
            if (! empty($data['idempotency_key'])) {
                $existing = Claim::where('organization_id', $workOrder->organization_id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $device = $worker->primaryDevice() ?? $workOrder->device;

            $claim = Claim::create([
                'organization_id' => $workOrder->organization_id,
                'work_order_id' => $workOrder->id,
                'user_id' => $worker->id,
                'device_id' => $device?->id,
                'reference' => ReferenceGenerator::next('claims', $workOrder->organization_id, 'CLM'),
                'claim_type' => $data['claim_type'] ?? 'SERVICE_COMPLETED',
                'claimed_at' => $data['claimed_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'context' => $data['context'] ?? null,
                'status' => ClaimStatus::SUBMITTED,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'submitted_at' => now(),
            ]);

            $workOrder->forceFill(['status' => WorkOrderStatus::AWAITING_VERIFICATION])->save();

            $this->audit->log(AuditEventType::CLAIM_CREATED, $claim, [
                // Same key name as every other event in a claim's life, so the
                // audit log can group a case together without special-casing.
                'claim_reference' => $claim->reference,
                'work_order_reference' => $workOrder->reference,
                'device' => $device?->reference,
            ], $worker);

            return $claim;
        });
    }
}
