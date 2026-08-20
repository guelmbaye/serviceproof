<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type->value,
            'actor' => [
                'type' => $this->actor_type,
                'name' => $this->whenLoaded('actor', fn () => $this->actor?->name),
            ],
            'resource' => [
                'type' => $this->resource_type,
                'id' => $this->resource_id,
            ],
            'metadata' => $this->metadata,
            'request_id' => $this->request_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
