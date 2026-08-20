<?php

namespace App\Domain\WorkOrders\Models;

use App\Domain\Claims\Models\Claim;
use App\Domain\Devices\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'organization_id', 'reference', 'customer_name', 'service_type', 'description',
        'site_name', 'site_address', 'site_latitude', 'site_longitude', 'site_radius_m',
        'assigned_user_id', 'device_id', 'policy_id', 'risk_level',
        'scheduled_at', 'window_starts_at', 'window_ends_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'window_starts_at' => 'datetime',
            'window_ends_at' => 'datetime',
            'site_latitude' => 'float',
            'site_longitude' => 'float',
            'status' => WorkOrderStatus::class,
        ];
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(VerificationPolicy::class, 'policy_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function latestClaim(): ?Claim
    {
        return $this->claims()->latest('created_at')->first();
    }

    public function scopeAssignedTo(Builder $query, string $userId): Builder
    {
        return $query->where('assigned_user_id', $userId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [WorkOrderStatus::CLOSED->value, WorkOrderStatus::CANCELLED->value]);
    }

    /** Expected operational area, used to build the CAMARA location request. */
    public function expectedArea(): array
    {
        return [
            'name' => $this->site_name,
            'latitude' => (float) $this->site_latitude,
            'longitude' => (float) $this->site_longitude,
            'radius_m' => (int) $this->site_radius_m,
        ];
    }
}
