<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'assurance_level' => $this->assurance_level->value,
            'required_evidence' => $this->required_evidence,
            'optional_evidence' => $this->optional_evidence,
            'evidence_budget' => [
                'max_tool_calls' => $this->max_tool_calls,
                'max_latency_ms' => $this->max_latency_ms,
            ],
            'location_radius_m' => $this->location_radius_m,
            'freshness_seconds' => $this->freshness_seconds,
            'allow_partial' => $this->allow_partial,
            'auto_close_on_verified' => $this->auto_close_on_verified,
            'allowed_tools' => $this->allowedTools(),
            'is_active' => $this->is_active,
        ];
    }
}
