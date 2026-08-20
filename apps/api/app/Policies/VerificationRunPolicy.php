<?php

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Verification\Models\VerificationRun;

class VerificationRunPolicy
{
    public function view(User $user, VerificationRun $run): bool
    {
        return $user->belongsToOrganization($run->organization_id) && $user->role->isBackOffice();
    }

    public function viewAny(User $user): bool
    {
        return $user->role->isBackOffice();
    }
}
