<?php

namespace Tests\Unit;

use App\Domain\Evidence\Models\Evidence;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Shared\Enums\DecisionState;
use App\Domain\Shared\Enums\EvidenceSource;
use App\Domain\Shared\Enums\EvidenceStatus;
use App\Domain\Shared\Enums\EvidenceType;
use App\Services\Verification\DecisionGuard;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class DecisionGuardTest extends TestCase
{
    private function policy(array $overrides = []): VerificationPolicy
    {
        $policy = new VerificationPolicy;

        $policy->forceFill(array_merge([
            'key' => 'STANDARD_FIELD_SERVICE',
            'required_evidence' => ['LOCATION_VERIFICATION'],
            'optional_evidence' => ['DEVICE_STATUS'],
            'allow_partial' => true,
            'assurance_level' => 'STANDARD',
        ], $overrides));

        return $policy;
    }

    private function evidence(EvidenceType $type, EvidenceStatus $status, EvidenceSource $source = EvidenceSource::CAMARA): Evidence
    {
        $evidence = new Evidence;
        $evidence->forceFill([
            'type' => $type->value,
            'status' => $status->value,
            'source' => $source->value,
        ]);

        return $evidence;
    }

    public function test_supported_required_evidence_yields_verified(): void
    {
        $guard = new DecisionGuard;

        $result = $guard->apply(
            new Collection([$this->evidence(EvidenceType::LOCATION_VERIFICATION, EvidenceStatus::SUPPORTED)]),
            $this->policy(),
            ['state' => 'VERIFIED']
        );

        $this->assertSame(DecisionState::VERIFIED, $result->state);
        $this->assertFalse($result->guardApplied);
    }

    public function test_an_agent_cannot_talk_the_system_into_verified_when_evidence_conflicts(): void
    {
        $guard = new DecisionGuard;

        $result = $guard->apply(
            new Collection([
                $this->evidence(EvidenceType::LOCATION_VERIFICATION, EvidenceStatus::CONFLICTING),
                $this->evidence(EvidenceType::DEVICE_STATUS, EvidenceStatus::SUPPORTED),
            ]),
            $this->policy(),
            ['state' => 'VERIFIED'] // the agent proposed VERIFIED
        );

        $this->assertSame(DecisionState::DISPUTED, $result->state);
        $this->assertTrue($result->guardApplied);
        $this->assertNotNull($result->guardReason);
    }

    public function test_unavailable_evidence_is_not_negative_evidence(): void
    {
        $guard = new DecisionGuard;

        $result = $guard->apply(
            new Collection([$this->evidence(EvidenceType::LOCATION_VERIFICATION, EvidenceStatus::UNAVAILABLE)]),
            $this->policy(),
            ['state' => 'DISPUTED']
        );

        $this->assertSame(DecisionState::UNVERIFIED, $result->state);
        $this->assertLessThanOrEqual(25, $result->assuranceScore);
    }

    public function test_a_strict_policy_refuses_partial_assurance(): void
    {
        $guard = new DecisionGuard;

        $result = $guard->apply(
            new Collection([$this->evidence(EvidenceType::DEVICE_STATUS, EvidenceStatus::SUPPORTED)]),
            $this->policy([
                'required_evidence' => ['LOCATION_VERIFICATION', 'DEVICE_STATUS'],
                'allow_partial' => false,
            ]),
            ['state' => 'PARTIAL']
        );

        $this->assertSame(DecisionState::UNVERIFIED, $result->state);
        $this->assertContains('LOCATION_VERIFICATION', $result->missingRequired);
    }

    public function test_simulated_evidence_is_flagged_on_the_decision(): void
    {
        $guard = new DecisionGuard;

        $result = $guard->apply(
            new Collection([
                $this->evidence(EvidenceType::LOCATION_VERIFICATION, EvidenceStatus::SUPPORTED, EvidenceSource::DEMO_FALLBACK),
            ]),
            $this->policy(),
            ['state' => 'VERIFIED']
        );

        $this->assertTrue($result->simulated);
        $this->assertSame('INCLUDES_SIMULATED_EVIDENCE', $result->breakdown['provenance']);
    }
}
