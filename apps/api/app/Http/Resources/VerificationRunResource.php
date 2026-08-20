<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VerificationRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'assurance_level' => $this->assurance_level->value,
            'policy' => $this->whenLoaded('policy', fn () => [
                'key' => $this->policy?->key,
                'name' => $this->policy?->name,
                'required_evidence' => $this->policy?->required_evidence,
            ]),
            'budget' => [
                'max_tool_calls' => $this->budget_max_tool_calls,
                'tool_calls_used' => $this->tool_calls_used,
                'remaining' => $this->budgetRemaining(),
                'max_latency_ms' => $this->budget_max_latency_ms,
            ],
            'evidence_plan' => $this->evidence_plan,
            'escalated' => $this->escalated,
            'used_demo_fallback' => $this->used_demo_fallback,
            'agent' => [
                'version' => $this->agent_version,
                'planner_mode' => $this->planner_mode,
                'provider' => $this->llm_provider,
                'model' => $this->llm_model,
            ],
            'duration_ms' => $this->duration_ms,
            'failure_reason' => $this->failure_reason,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'evidence' => EvidenceResource::collection($this->whenLoaded('evidence')),
            'trace' => TraceEventResource::collection($this->whenLoaded('traceEvents')),
            'decision' => new DecisionResource($this->whenLoaded('decision')),
        ];
    }
}
