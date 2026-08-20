<?php

namespace Tests\Feature;

use App\Domain\Evidence\Models\Evidence;
use App\Domain\Shared\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EvidenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorded_evidence_cannot_be_modified(): void
    {
        $organization = $this->createOrganization();
        $worker = $this->createUser($organization, Role::FIELD_WORKER);
        $manager = $this->createUser($organization, Role::OPERATIONS_MANAGER);
        $workOrder = $this->createWorkOrder($organization, $worker);

        $claimId = $this->actingAs($worker, 'sanctum')
            ->postJson("/api/v1/work-orders/{$workOrder->id}/claims", ['idempotency_key' => 'immutable-1'])
            ->json('data.id');

        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/claims/{$claimId}/verify")->assertOk();

        $evidence = Evidence::withoutGlobalScopes()->firstOrFail();

        $this->expectException(RuntimeException::class);
        $evidence->forceFill(['status' => 'SUPPORTED'])->save();
    }

    public function test_a_client_cannot_submit_its_own_verification_result(): void
    {
        $organization = $this->createOrganization();
        $worker = $this->createUser($organization, Role::FIELD_WORKER);
        $workOrder = $this->createWorkOrder($organization, $worker);

        $response = $this->actingAs($worker, 'sanctum')
            ->postJson("/api/v1/work-orders/{$workOrder->id}/claims", [
                'idempotency_key' => 'spoof-1',
                'status' => 'RESOLVED',
                'decision' => 'VERIFIED',
                'evidence' => [['type' => 'LOCATION_VERIFICATION', 'status' => 'SUPPORTED']],
            ]);

        $response->assertCreated();

        // The unexpected fields are simply not part of the claim contract.
        $this->assertSame('SUBMITTED', $response->json('data.status'));
        $this->assertDatabaseCount('evidence', 0);
    }
}
