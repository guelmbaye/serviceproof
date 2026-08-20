<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status,
            'employee_reference' => $this->employee_reference,
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization?->id,
                'name' => $this->organization?->name,
                'default_policy_key' => $this->organization?->default_policy_key,
            ]),
            'capabilities' => [
                'back_office' => $this->role->isBackOffice(),
                'can_review' => $this->role->canReview(),
                'can_administer' => $this->role->canAdministerOrganization(),
                'can_trigger_verification' => $this->role->canTriggerVerification(),
            ],
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
