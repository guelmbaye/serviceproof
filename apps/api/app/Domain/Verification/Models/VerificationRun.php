<?php

namespace App\Domain\Verification\Models;

use App\Domain\Claims\Models\Claim;
use App\Domain\Decisions\Models\Decision;
use App\Domain\Evidence\Models\Evidence;
use App\Domain\Identity\Models\User;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Enums\AssuranceLevel;
use App\Domain\Shared\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VerificationRun extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id', 'claim_id', 'policy_id', 'triggered_by', 'status',
        'assurance_level', 'budget_max_tool_calls', 'tool_calls_used',
        'budget_max_latency_ms', 'duration_ms', 'escalated', 'used_demo_fallback',
        'agent_version', 'llm_provider', 'llm_model', 'planner_mode',
        'evidence_plan', 'failure_reason', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence_plan' => 'array',
            'escalated' => 'boolean',
            'used_demo_fallback' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => VerificationStatus::class,
            'assurance_level' => AssuranceLevel::class,
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(VerificationPolicy::class, 'policy_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class)->orderBy('received_at');
    }

    public function traceEvents(): HasMany
    {
        return $this->hasMany(AgentTraceEvent::class)->orderBy('sequence');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(Decision::class)->where('is_current', true);
    }

    public function budgetRemaining(): int
    {
        return max(0, (int) $this->budget_max_tool_calls - (int) $this->tool_calls_used);
    }
}
