<?php

namespace App\Domain\Verification\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Enums\TraceEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An observable action trace. This is deliberately NOT the model's private
 * reasoning: it records which tool was selected, what evidence came back,
 * which policy was applied, and what decision resulted.
 */
class AgentTraceEvent extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id', 'verification_run_id', 'sequence',
        'event_type', 'label', 'detail', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'occurred_at' => 'datetime',
            'event_type' => TraceEventType::class,
        ];
    }

    public function verificationRun(): BelongsTo
    {
        return $this->belongsTo(VerificationRun::class);
    }
}
