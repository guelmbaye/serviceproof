<?php

namespace App\Domain\Decisions\Models;

use App\Domain\Claims\Models\Claim;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Enums\DecisionState;
use App\Domain\Shared\Enums\RecommendedAction;
use App\Domain\Verification\Models\VerificationRun;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Decision extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id', 'verification_run_id', 'claim_id', 'state',
        'recommended_action', 'assurance_score', 'assurance_breakdown', 'rationale',
        'origin', 'policy_satisfied', 'guard_applied', 'guard_reason', 'simulated',
        'is_current', 'superseded_by',
    ];

    protected function casts(): array
    {
        return [
            'assurance_breakdown' => 'array',
            'policy_satisfied' => 'boolean',
            'guard_applied' => 'boolean',
            'simulated' => 'boolean',
            'is_current' => 'boolean',
            'state' => DecisionState::class,
            'recommended_action' => RecommendedAction::class,
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function verificationRun(): BelongsTo
    {
        return $this->belongsTo(VerificationRun::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Decision::class, 'superseded_by');
    }
}
