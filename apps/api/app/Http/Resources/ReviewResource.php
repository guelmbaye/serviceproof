<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'outcome' => $this->outcome,
            'override_state' => $this->override_state,
            'resolution_notes' => $this->resolution_notes,
            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'resolved_by' => $this->whenLoaded('resolver', fn () => $this->resolver?->name),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'claim' => new ClaimResource($this->whenLoaded('claim')),
            'decision' => new DecisionResource($this->whenLoaded('decision')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
