<?php

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\WorkOrders\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        if (! $user->belongsToOrganization($workOrder->organization_id)) {
            return false;
        }

        // A field worker only sees their own assignments.
        return $user->role !== Role::FIELD_WORKER || $workOrder->assigned_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::ORG_ADMIN, Role::OPERATIONS_MANAGER);
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasRole(Role::ORG_ADMIN, Role::OPERATIONS_MANAGER)
            && $user->belongsToOrganization($workOrder->organization_id);
    }

    public function submitClaim(User $user, WorkOrder $workOrder): bool
    {
        return $user->belongsToOrganization($workOrder->organization_id)
            && ($workOrder->assigned_user_id === $user->id
                || $user->hasRole(Role::ORG_ADMIN, Role::OPERATIONS_MANAGER));
    }
}
