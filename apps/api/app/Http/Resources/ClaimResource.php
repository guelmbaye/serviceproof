<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->claim_type,
            'status' => $this->status->value,
            'claimed_at' => $this->claimed_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'notes' => $this->notes,
            'work_order' => $this->whenLoaded('workOrder', fn () => [
                'id' => $this->workOrder->id,
                'reference' => $this->workOrder->reference,
                'customer' => $this->workOrder->customer_name,
                'site' => $this->workOrder->site_name,
                'status' => $this->workOrder->status->value,
            ]),
            'worker' => $this->whenLoaded('user', fn () => [
                'reference' => $this->user->employee_reference,
                'name' => $this->user->name,
            ]),
            'device' => $this->whenLoaded('device', fn () => [
                'reference' => $this->device?->reference,
            ]),
            'decision' => new DecisionResource($this->whenLoaded('currentDecision')),
            'verification' => new VerificationRunResource($this->whenLoaded('latestRun')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
