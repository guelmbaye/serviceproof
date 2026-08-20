<?php

namespace App\Domain\Audit\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AuditEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only audit log. Answers "why did ServiceProof make this decision?"
 * without exposing private model reasoning.
 */
class AuditEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'actor_id', 'actor_type', 'event_type',
        'resource_type', 'resource_id', 'metadata', 'request_id',
        'ip_address', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'event_type' => AuditEventType::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Audit events are append-only.'));
        static::deleting(fn () => throw new RuntimeException('Audit events cannot be deleted.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
