<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:80', 'unique:organizations,slug'],
            'country' => ['nullable', 'string', 'size:2'],
            'default_policy_key' => ['nullable', 'string', 'max:80'],
            'admin' => ['nullable', 'array'],
            'admin.name' => ['required_with:admin', 'string', 'max:180'],
            'admin.email' => ['required_with:admin', 'email', 'unique:users,email'],
            'admin.password' => ['required_with:admin', 'string', 'min:10'],
        ];
    }
}
