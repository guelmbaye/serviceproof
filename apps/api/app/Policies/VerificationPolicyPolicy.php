<?php

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Shared\Enums\Role;

class VerificationPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isBackOffice();
    }

    public function view(User $user, VerificationPolicy $policy): bool
    {
        return $user->belongsToOrganization($policy->organization_id) && $user->role->isBackOffice();
    }

    public function update(User $user, VerificationPolicy $policy): bool
    {
        return $user->hasRole(Role::ORG_ADMIN) && $user->belongsToOrganization($policy->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::ORG_ADMIN);
    }
}
