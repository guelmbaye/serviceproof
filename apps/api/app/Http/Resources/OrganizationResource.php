<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'country' => $this->country,
            'status' => $this->status,
            'default_policy_key' => $this->default_policy_key,
            'counts' => $this->when(isset($this->users_count), fn () => [
                'users' => $this->users_count ?? null,
                'work_orders' => $this->work_orders_count ?? null,
                'claims' => $this->claims_count ?? null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
