<?php

namespace App\Domain\Evidence\Models;

use App\Domain\Claims\Models\Claim;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Enums\EvidenceSource;
use App\Domain\Shared\Enums\EvidenceStatus;
use App\Domain\Shared\Enums\EvidenceType;
use App\Domain\Verification\Models\VerificationRun;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Immutable evidence record.
 *
 * Once written, an evidence item is never updated. A later re-interpretation
 * produces a new assessment; the original observation stands untouched.
 */
class Evidence extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'evidence';

    protected $fillable = [
        'organization_id', 'verification_run_id', 'claim_id', 'type', 'status',
        'source', 'provider', 'api_name', 'request_id', 'freshness', 'age_seconds',
        'latency_ms', 'reliability', 'observed_at', 'received_at', 'summary',
        'normalized', 'payload_hash', 'raw_reference', 'failure_reason',
    ];

    protected $hidden = ['raw_reference'];

    protected function casts(): array
    {
        return [
            'normalized' => 'array',
            'raw_reference' => 'array',
            'observed_at' => 'datetime',
            'received_at' => 'datetime',
            'reliability' => 'float',
            'type' => EvidenceType::class,
            'status' => EvidenceStatus::class,
            'source' => EvidenceSource::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Evidence $evidence) {
            throw new RuntimeException(
                'Evidence records are immutable. Record a new assessment instead of mutating evidence '.$evidence->id.'.'
            );
        });

        static::deleting(function (Evidence $evidence) {
            throw new RuntimeException('Evidence records cannot be deleted individually.');
        });
    }

    public function verificationRun(): BelongsTo
    {
        return $this->belongsTo(VerificationRun::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    public function isSimulated(): bool
    {
        return $this->source === EvidenceSource::DEMO_FALLBACK;
    }

    /** Provenance block shown in the UI — "where did this evidence come from?" */
    public function provenance(): array
    {
        return [
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'provider' => $this->provider,
            'api' => $this->api_name,
            'request_id' => $this->request_id,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'freshness' => $this->freshness,
            'age_seconds' => $this->age_seconds,
            'latency_ms' => $this->latency_ms,
        ];
    }
}
