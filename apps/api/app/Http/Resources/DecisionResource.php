<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'recommended_action' => $this->recommended_action->value,
            'recommended_action_label' => $this->recommended_action->label(),
            'requires_review' => $this->state->requiresHumanReview(),
            'assurance' => [
                'score' => $this->assurance_score,
                'breakdown' => $this->assurance_breakdown,
            ],
            'rationale' => $this->rationale,
            'origin' => $this->origin,
            'policy_satisfied' => $this->policy_satisfied,
            'guard' => [
                'applied' => $this->guard_applied,
                'reason' => $this->guard_reason,
            ],
            'simulated' => $this->simulated,
            'is_current' => $this->is_current,
            'superseded_by' => $this->superseded_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
