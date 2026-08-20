<?php

namespace App\Domain\Claims\Models;

use App\Domain\Decisions\Models\Decision;
use App\Domain\Devices\Models\Device;
use App\Domain\Evidence\Models\Evidence;
use App\Domain\Identity\Models\User;
use App\Domain\Reviews\Models\Review;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Enums\ClaimStatus;
use App\Domain\Verification\Models\VerificationRun;
use App\Domain\WorkOrders\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Claim extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id', 'work_order_id', 'user_id', 'device_id', 'reference',
        'claim_type', 'claimed_at', 'notes', 'context', 'status',
        'idempotency_key', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'context' => 'array',
            'status' => ClaimStatus::class,
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function verificationRuns(): HasMany
    {
        return $this->hasMany(VerificationRun::class);
    }

    /**
     * The most recent verification run for this claim.
     *
     * Deliberately ordered rather than expressed with latestOfMany(). That
     * helper builds a `MAX(id)` subquery, and PostgreSQL has no max(uuid) —
     * every primary key here is a UUID, so it fails outright. Ordering also
     * gives a real tiebreak: started_at is stored at second precision, so two
     * runs opened in the same second would otherwise be indistinguishable.
     * The ids are UUIDv7, which sort by creation time, so they settle it.
     *
     * Ordering works under eager loading too: Laravel matches the first row
     * per parent out of the ordered result set.
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(VerificationRun::class)
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }

    public function currentDecision(): HasOne
    {
        return $this->hasOne(Decision::class)->where('is_current', true);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Claim text is untrusted input. It is passed to the agent explicitly
     * labelled as such, and the agent's tool/policy constraints outrank it.
     */
    public function untrustedNotes(): ?string
    {
        return $this->notes;
    }
}
