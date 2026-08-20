<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A claim carries an assertion and app-reported context only.
     * It can never carry an evidence status or a decision.
     */
    public function rules(): array
    {
        return [
            'claim_type' => ['nullable', 'string', 'in:SERVICE_COMPLETED,SERVICE_ATTEMPTED,SITE_VISIT,EQUIPMENT_REPLACED'],
            // A claim is stamped on the handset at the moment the technician
            // taps, and phones drift. `before_or_equal:now` rejected anything
            // from a device whose clock ran even a second fast — a 422, which
            // the offline outbox cannot usefully retry, so the technician's
            // work was simply lost.
            //
            // The tolerance matches CLOCK_SKEW_TOLERANCE_SECONDS in the agent's
            // normaliser: beyond five minutes a timestamp is not skew, it is
            // wrong, and a claim dated in the future is worth refusing.
            'claimed_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'context' => ['nullable', 'array'],
            'context.app_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'context.app_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'context.app_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'context.offline_captured_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'claimed_at.before_or_equal' => 'A claim cannot be dated in the future.',
        ];
    }
}
