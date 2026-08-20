<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'customer' => $this->customer_name,
            'service_type' => $this->service_type,
            'description' => $this->description,
            'site' => [
                'name' => $this->site_name,
                'address' => $this->site_address,
                'latitude' => $this->site_latitude,
                'longitude' => $this->site_longitude,
                'radius_m' => $this->site_radius_m,
            ],
            'risk_level' => $this->risk_level,
            'status' => $this->status->value,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'window' => [
                'starts_at' => $this->window_starts_at?->toIso8601String(),
                'ends_at' => $this->window_ends_at?->toIso8601String(),
            ],
            'assigned_to' => $this->whenLoaded('assignedUser', fn () => [
                'id' => $this->assignedUser?->id,
                'name' => $this->assignedUser?->name,
                'reference' => $this->assignedUser?->employee_reference,
            ]),
            'device' => $this->whenLoaded('device', fn () => [
                'reference' => $this->device?->reference,
            ]),
            'policy' => $this->whenLoaded('policy', fn () => [
                'key' => $this->policy?->key,
                'name' => $this->policy?->name,
            ]),
            'claims' => ClaimResource::collection($this->whenLoaded('claims')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
