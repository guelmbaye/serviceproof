<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->canAdministerOrganization() ?? false;
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:120'],
            'identifier_type' => ['required', 'in:PHONE_NUMBER,NETWORK_ACCESS_IDENTIFIER,IPV4,IPV6'],
            'network_identifier' => ['required', 'string', 'max:180'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'is_simulator' => ['nullable', 'boolean'],
        ];
    }
}
