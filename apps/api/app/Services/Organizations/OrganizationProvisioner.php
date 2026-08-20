<?php

namespace App\Services\Organizations;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Shared\Enums\AuditEventType;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationProvisioner
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $data): Organization
    {
        return DB::transaction(function () use ($data) {
            $organization = Organization::create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
                'country' => $data['country'] ?? null,
                'status' => 'ACTIVE',
                'default_policy_key' => $data['default_policy_key'] ?? config('serviceproof.default_policy'),
                'configuration' => $data['configuration'] ?? null,
            ]);

            $this->seedPolicies($organization);

            $this->audit->log(AuditEventType::ORGANIZATION_CREATED, $organization, [
                'name' => $organization->name,
            ]);

            return $organization;
        });
    }

    /** Every tenant starts with the built-in policy templates. */
    public function seedPolicies(Organization $organization): void
    {
        foreach (config('serviceproof.policy_templates', []) as $key => $template) {
            VerificationPolicy::withoutGlobalScope('organization')->updateOrCreate(
                ['organization_id' => $organization->id, 'key' => $key],
                [
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'assurance_level' => $template['assurance_level'],
                    'required_evidence' => $template['required_evidence'],
                    'optional_evidence' => $template['optional_evidence'],
                    'max_tool_calls' => $template['max_tool_calls'],
                    'max_latency_ms' => $template['max_latency_ms'],
                    'location_radius_m' => $template['location_radius_m'],
                    'freshness_seconds' => $template['freshness_seconds'],
                    'allow_partial' => $template['allow_partial'],
                    'auto_close_on_verified' => $template['auto_close_on_verified'],
                    'is_active' => true,
                ]
            );
        }
    }
}
