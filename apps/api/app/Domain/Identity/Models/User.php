<?php

namespace App\Domain\Identity\Models;

use App\Domain\Claims\Models\Claim;
use App\Domain\Devices\Models\Device;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Shared\Enums\Role;
use App\Domain\WorkOrders\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'email', 'phone', 'password',
        'role', 'status', 'employee_reference',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function primaryDevice(): ?Device
    {
        return $this->devices()->where('status', 'ACTIVE')->first();
    }

    public function assignedWorkOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'assigned_user_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SUPER_ADMIN;
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function belongsToOrganization(?string $organizationId): bool
    {
        return $this->isSuperAdmin() || ($organizationId !== null && $this->organization_id === $organizationId);
    }
}
