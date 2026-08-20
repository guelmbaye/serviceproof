<?php

namespace App\Http\Requests;

use App\Domain\Shared\Enums\EvidenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $types = array_column(EvidenceType::cases(), 'value');

        return [
            'name' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'assurance_level' => ['sometimes', 'in:LOW,STANDARD,HIGH'],
            'required_evidence' => ['sometimes', 'array', 'min:1'],
            'required_evidence.*' => [Rule::in($types)],
            'optional_evidence' => ['sometimes', 'array'],
            'optional_evidence.*' => [Rule::in($types)],
            'max_tool_calls' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'max_latency_ms' => ['sometimes', 'integer', 'min:1000', 'max:60000'],
            'location_radius_m' => ['sometimes', 'integer', 'min:50', 'max:50000'],
            'freshness_seconds' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'allow_partial' => ['sometimes', 'boolean'],
            'auto_close_on_verified' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
