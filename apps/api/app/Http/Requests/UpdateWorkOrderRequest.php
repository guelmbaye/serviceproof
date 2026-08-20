<?php

namespace App\Http\Requests;

use App\Domain\Shared\Enums\WorkOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['sometimes', 'string', 'max:180'],
            'service_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'site_name' => ['sometimes', 'string', 'max:180'],
            'site_latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'site_longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'site_radius_m' => ['sometimes', 'integer', 'min:50', 'max:50000'],
            'assigned_user_id' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
            'device_id' => ['sometimes', 'nullable', 'uuid', 'exists:devices,id'],
            'policy_id' => ['sometimes', 'nullable', 'uuid', 'exists:verification_policies,id'],
            'risk_level' => ['sometimes', 'in:LOW,NORMAL,HIGH'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            // Verification outcomes are never client-settable.
            'status' => ['sometimes', new Enum(WorkOrderStatus::class), 'not_in:VERIFIED,DISPUTED,NEEDS_REVIEW'],
        ];
    }
}
