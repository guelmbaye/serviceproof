<?php

namespace App\Domain\Reviews\Models;

use App\Domain\Claims\Models\Claim;
use App\Domain\Decisions\Models\Decision;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id', 'claim_id', 'decision_id', 'assigned_to', 'resolved_by',
        'status', 'reason', 'outcome', 'override_state', 'resolution_notes', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'status' => ReviewStatus::class,
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [ReviewStatus::OPEN->value, ReviewStatus::IN_PROGRESS->value]);
    }
}
