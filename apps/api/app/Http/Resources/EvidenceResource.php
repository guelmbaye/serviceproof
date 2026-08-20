<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Evidence Card contract. Provenance is always visible; the raw
 * provider payload never leaves the backend.
 */
class EvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'api' => $this->api_name,
            'business_question' => $this->type->businessQuestion(),
            'status' => $this->status->value,
            'usable' => $this->status->isUsable(),
            'summary' => $this->summary,
            'reliability' => $this->reliability,
            'simulated' => $this->isSimulated(),
            'provenance' => $this->provenance(),
            'normalized' => $this->normalized,
            'failure_reason' => $this->failure_reason,
        ];
    }
}
