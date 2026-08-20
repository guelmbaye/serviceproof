<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', 'in:CONFIRMED,OVERRIDDEN'],
            'override_state' => ['required_if:outcome,OVERRIDDEN', 'nullable', 'in:VERIFIED,PARTIAL,DISPUTED,UNVERIFIED'],
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'A reason is required: every manual override is recorded in the audit trail.',
        ];
    }
}
