<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->canAdministerOrganization() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:10'],
            'role' => ['required', Rule::in($this->user()->role->assignableRoles())],
            'employee_reference' => ['nullable', 'string', 'max:64'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
        ];
    }
}
