<?php

namespace Tests\Feature;

use App\Domain\Claims\Models\Claim;
use App\Domain\Decisions\Models\Decision;
use App\Domain\Reviews\Models\Review;
use App\Domain\Shared\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_field_worker_submits_a_claim_and_the_agent_loop_produces_a_verified_decision(): void
    {
        $organization = $this->createOrganization();
        $worker = $this->createUser($organization, Role::FIELD_WORKER);
        $manager = $this->createUser($organization, Role::OPERATIONS_MANAGER);
        $workOrder = $this->createWorkOrder($organization, $worker);

        $claimResponse = $this->actingAs($worker, 'sanctum')
            ->postJson("/api/v1/work-orders/{$workOrder->id}/claims", [
                'claim_type' => 'SERVICE_COMPLETED',
                'notes' => 'Module replaced and link re-tested.',
                'idempotency_key' => 'test-key-1',
            ]);

        $claimResponse->assertCreated();
        $claimId = $claimResponse->json('data.id');

        $verifyResponse = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/claims/{$claimId}/verify");

        $verifyResponse->assertOk()
            ->assertJsonPath('data.decision.state', 'VERIFIED')
            ->assertJsonPath('data.decision.policy_satisfied', true);

        $this->assertNotEmpty($verifyResponse->json('data.trace'));
        $this->assertNotEmpty($verifyResponse->json('data.evidence'));

        $this->assertDatabaseHas('decisions', ['claim_id' => $claimId, 'state' => 'VERIFIED', 'is_current' => true]);
    }

    public function test_conflicting_evidence_produces_a_disputed_decision_and_opens_a_review(): void
    {
        $organization = $this->createOrganization();
        $worker = $this->createUser($organization, Role::FIELD_WORKER);
        $manager = $this->createUser($organization, Role::OPERATIONS_MANAGER);
        $workOrder = $this->createWorkOrder($organization, $worker);

        $claimId = $this->actingAs($worker, 'sanctum')
            ->postJson("/api/v1/work-orders/{$workOrder->id}/claims", ['idempotency_key' => 'test-key-2'])
            ->json('data.id');

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/claims/{$claimId}/verify", ['scenario' => 'DISPUTED'])
            ->assertOk()
            ->assertJsonPath('data.decision.state', 'DISPUTED')
            ->assertJsonPath('data.escalated', true);

        $this->assertDatabaseHas('reviews', ['claim_id' => $claimId, 'status' => 'OPEN', 'reason' => 'DISPUTED_EVIDENCE']);
    }

    public function test_an_unavailable_network_api_yields_unverified_and_never_a_false_claim(): void
    {
        $organization = $this->createOrganization();
        $worker = $this->createUser($organization, Role::FIELD_WORKER);
        $manager = $this->createUser($organization, Role::OPERATIONS_MANAGER);
        $workOrder = $this->createWorkOrder($organization, $worker);

        $claimId = $this->actingAs($worker, 'sanctum')
            ->postJson("/api/v1/work-orders/{$workOrder->id}/claims", ['idempotency_key' => 'test-key-3'])
            ->json('data.id');

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/claims/{$claimId}/verify", ['scenario' => 'UNVERIFIED'])
            ->assertOk()
            ->assertJsonPath('data.decision.state', 'UNVERIFIED');

        // An API failure is an absence of evidence, not evidence of absence.
        $this->assertDatabaseMissing('decisions', ['claim_id' => $claimId, 'state' => 'DISPUTED']);
    }

    public function test_claim_submission_is_idempotent(): void
    {
        $organization = $this->createOrganization();
        $worker = $this->createUser($organization, Role::FIELD_WORKER);
        $workOrder = $this->createWorkOrder($organization, $worker);

        $payload = ['idempotency_key' => 'offline-retry-1'];

        $first = $this->actingAs($worker, 'sanctum')->postJson("/api/v1/work-orders/{$workOrder->id}/claims", $payload);
        $second = $this->actingAs($worker, 'sanctum')->postJson("/api/v1/work-orders/{$workOrder->id}/claims", $payload);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Claim::withoutGlobalScopes()->count());
    }

    public function test_a_reviewer_override_supersedes_rather_than_overwrites_the_decision(): void
    {
        $organization = $this->createOrganization();
        $worker = $this->createUser($organization, Role::FIELD_WORKER);
        $manager = $this->createUser($organization, Role::OPERATIONS_MANAGER);
        $reviewer = $this->createUser($organization, Role::REVIEWER);
        $workOrder = $this->createWorkOrder($organization, $worker);

        $claimId = $this->actingAs($worker, 'sanctum')
            ->postJson("/api/v1/work-orders/{$workOrder->id}/claims", ['idempotency_key' => 'test-key-4'])
            ->json('data.id');

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/claims/{$claimId}/verify", ['scenario' => 'DISPUTED'])
            ->assertOk();

        $review = Review::withoutGlobalScopes()->where('claim_id', $claimId)->firstOrFail();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/v1/reviews/{$review->id}/resolve", [
                'outcome' => 'OVERRIDDEN',
                'override_state' => 'VERIFIED',
                'notes' => 'Signed customer worksheet provided out of band.',
            ])
            ->assertOk();

        $decisions = Decision::withoutGlobalScopes()->where('claim_id', $claimId)->get();

        $this->assertCount(2, $decisions, 'The original decision must remain readable.');
        $this->assertSame('VERIFIED', $decisions->firstWhere('is_current', true)->state->value);
        $this->assertSame('HUMAN', $decisions->firstWhere('is_current', true)->origin);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'DECISION_OVERRIDDEN']);
    }
}
