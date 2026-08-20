<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Observable decision trace. Not model chain-of-thought.
 */
class TraceEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sequence' => $this->sequence,
            'event_type' => $this->event_type->value,
            'label' => $this->label,
            'detail' => $this->detail,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
