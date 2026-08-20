<?php

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Shared\Enums\Role;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization->id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasRole(Role::ORG_ADMIN) && $user->belongsToOrganization($organization->id);
    }

    public function suspend(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
