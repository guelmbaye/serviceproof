<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorisation happens through the WorkOrderPolicy.
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:180'],
            'service_type' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'site_name' => ['required', 'string', 'max:180'],
            'site_address' => ['nullable', 'string', 'max:255'],
            'site_latitude' => ['required', 'numeric', 'between:-90,90'],
            'site_longitude' => ['required', 'numeric', 'between:-180,180'],
            'site_radius_m' => ['nullable', 'integer', 'min:50', 'max:50000'],
            'assigned_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'device_id' => ['nullable', 'uuid', 'exists:devices,id'],
            'policy_id' => ['nullable', 'uuid', 'exists:verification_policies,id'],
            'risk_level' => ['nullable', 'in:LOW,NORMAL,HIGH'],
            'scheduled_at' => ['nullable', 'date'],
            'window_starts_at' => ['nullable', 'date'],
            'window_ends_at' => ['nullable', 'date', 'after_or_equal:window_starts_at'],
        ];
    }
}
