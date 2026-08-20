<?php

namespace App\Http\Resources;

use App\Domain\Shared\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'label' => $this->label,
            'identifier_type' => $this->identifier_type,
            // The raw network identifier is sensitive. Only an org admin sees
            // a masked form of it; nobody sees it in full through the API.
            'identifier_masked' => $this->when(
                $request->user()?->hasRole(Role::ORG_ADMIN, Role::SUPER_ADMIN),
                fn () => $this->maskIdentifier()
            ),
            'is_simulator' => $this->is_simulator,
            'status' => $this->status,
            'assigned_to' => $this->whenLoaded('user', fn () => $this->user?->employee_reference),
        ];
    }

    private function maskIdentifier(): string
    {
        $value = (string) $this->resource->network_identifier;
        $len = mb_strlen($value);

        return $len <= 4 ? str_repeat('*', $len) : str_repeat('*', $len - 4).mb_substr($value, -4);
    }
}
