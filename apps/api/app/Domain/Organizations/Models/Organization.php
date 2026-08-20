<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Claims\Models\Claim;
use App\Domain\Devices\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\WorkOrders\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'country', 'status', 'default_policy_key', 'configuration',
    ];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(VerificationPolicy::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
