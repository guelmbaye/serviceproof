<?php

namespace App\Policies;

use App\Domain\Claims\Models\Claim;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;

class ClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Claim $claim): bool
    {
        if (! $user->belongsToOrganization($claim->organization_id)) {
            return false;
        }

        return $user->role !== Role::FIELD_WORKER || $claim->user_id === $user->id;
    }

    public function verify(User $user, Claim $claim): bool
    {
        return $user->belongsToOrganization($claim->organization_id)
            && $user->role->canTriggerVerification();
    }

    public function viewEvidence(User $user, Claim $claim): bool
    {
        // Field workers see the outcome of their claim, not the raw
        // network evidence behind it.
        return $user->belongsToOrganization($claim->organization_id)
            && $user->role->isBackOffice();
    }
}
