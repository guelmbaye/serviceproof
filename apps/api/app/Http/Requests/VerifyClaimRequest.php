<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Demo controls. `force_mode=demo` pins the clearly-labelled
            // fallback adapter; it never disguises simulated data as live.
            'force_mode' => ['nullable', 'in:live,demo'],
            'scenario' => ['nullable', 'in:VERIFIED,DISPUTED,UNVERIFIED'],
        ];
    }
}
