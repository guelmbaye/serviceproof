<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_cannot_read_another_organizations_work_order(): void
    {
        $orgA = $this->createOrganization('Org A');
        $orgB = $this->createOrganization('Org B');

        $workerA = $this->createUser($orgA, Role::FIELD_WORKER);
        $managerB = $this->createUser($orgB, Role::OPERATIONS_MANAGER);

        $workOrder = $this->createWorkOrder($orgA, $workerA);

        $this->actingAs($managerB, 'sanctum')
            ->getJson("/api/v1/work-orders/{$workOrder->id}")
            ->assertNotFound();
    }

    public function test_a_field_worker_only_sees_their_own_assignments(): void
    {
        $organization = $this->createOrganization();
        $workerOne = $this->createUser($organization, Role::FIELD_WORKER);
        $workerTwo = $this->createUser($organization, Role::FIELD_WORKER);

        $this->createWorkOrder($organization, $workerOne);
        $this->createWorkOrder($organization, $workerTwo);

        $response = $this->actingAs($workerOne, 'sanctum')->getJson('/api/v1/work-orders');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_a_field_worker_cannot_read_raw_network_evidence(): void
    {
        $organization = $this->createOrganization();
        $worker = $this->createUser($organization, Role::FIELD_WORKER);
        $manager = $this->createUser($organization, Role::OPERATIONS_MANAGER);
        $workOrder = $this->createWorkOrder($organization, $worker);

        $claimId = $this->actingAs($worker, 'sanctum')
            ->postJson("/api/v1/work-orders/{$workOrder->id}/claims", ['idempotency_key' => 'iso-1'])
            ->json('data.id');

        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/claims/{$claimId}/verify")->assertOk();

        $this->actingAs($worker, 'sanctum')
            ->getJson("/api/v1/claims/{$claimId}/evidence")
            ->assertForbidden();
    }
}
