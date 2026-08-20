<?php

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Reviews\Models\Review;

class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canReview();
    }

    public function view(User $user, Review $review): bool
    {
        return $user->role->canReview() && $user->belongsToOrganization($review->organization_id);
    }

    public function resolve(User $user, Review $review): bool
    {
        return $this->view($user, $review);
    }
}
