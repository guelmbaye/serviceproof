<?php

namespace App\Policies;

use App\Domain\Devices\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canAdministerOrganization();
    }

    public function view(User $user, Device $device): bool
    {
        return $user->belongsToOrganization($device->organization_id)
            && ($user->role->canAdministerOrganization() || $device->user_id === $user->id);
    }

    public function manage(User $user, Device $device): bool
    {
        return $user->hasRole(Role::ORG_ADMIN) && $user->belongsToOrganization($device->organization_id);
    }
}
