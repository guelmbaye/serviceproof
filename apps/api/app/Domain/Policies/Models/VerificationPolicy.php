<?php

namespace App\Domain\Policies\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Enums\AssuranceLevel;
use App\Domain\Shared\Enums\EvidenceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The deterministic half of the system. The agent decides HOW to obtain
 * evidence; the policy decides WHAT IS SUFFICIENT.
 */
class VerificationPolicy extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id', 'key', 'name', 'description', 'assurance_level',
        'required_evidence', 'optional_evidence', 'max_tool_calls', 'max_latency_ms',
        'location_radius_m', 'freshness_seconds', 'allow_partial',
        'auto_close_on_verified', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'required_evidence' => 'array',
            'optional_evidence' => 'array',
            'allow_partial' => 'boolean',
            'auto_close_on_verified' => 'boolean',
            'is_active' => 'boolean',
            'assurance_level' => AssuranceLevel::class,
        ];
    }

    /** @return array<int, EvidenceType> */
    public function requiredEvidenceTypes(): array
    {
        return array_values(array_filter(array_map(
            fn (string $type) => EvidenceType::tryFrom($type),
            $this->required_evidence ?? []
        )));
    }

    /** @return array<int, EvidenceType> */
    public function optionalEvidenceTypes(): array
    {
        return array_values(array_filter(array_map(
            fn (string $type) => EvidenceType::tryFrom($type),
            $this->optional_evidence ?? []
        )));
    }

    /** Tool names the agent is allowed to select for this policy. */
    public function allowedTools(): array
    {
        $enabled = config('serviceproof.enabled_tools', []);

        $tools = array_map(
            fn (EvidenceType $type) => $type->tool(),
            array_merge($this->requiredEvidenceTypes(), $this->optionalEvidenceTypes())
        );

        return array_values(array_intersect(array_unique($tools), $enabled));
    }

    /** Contract sent to the agent runtime. */
    public function toAgentPayload(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'assurance_level' => $this->assurance_level->value,
            'required_evidence' => $this->required_evidence ?? [],
            'optional_evidence' => $this->optional_evidence ?? [],
            'max_tool_calls' => (int) $this->max_tool_calls,
            'max_latency_ms' => (int) $this->max_latency_ms,
            'location_radius_m' => (int) $this->location_radius_m,
            'freshness_seconds' => (int) $this->freshness_seconds,
            'allow_partial' => (bool) $this->allow_partial,
            'allowed_tools' => $this->allowedTools(),
        ];
    }
}
