<?php

namespace App\Domain\Network\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A CloudEvent the operator pushed to us.
 *
 * Not evidence, and the distinction is the whole point. Evidence is something
 * we asked for over an authenticated channel and normalised through a tool we
 * control. This arrived unsolicited on a public endpoint, unsigned, and the
 * only thing standing between it and a stranger is an unguessable URL.
 *
 * So it is stored, displayed, and never read by a decision.
 */
class NetworkEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id', 'event_type', 'spec_version', 'source', 'occurred_at',
        'subscription_id', 'device_identifier', 'provider', 'payload', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** The short, human form of a CAMARA event type. */
    public function label(): string
    {
        return match (true) {
            str_ends_with($this->event_type, 'area-entered') => 'Area entered',
            str_ends_with($this->event_type, 'area-left') => 'Area left',
            default => $this->event_type,
        };
    }

    /**
     * The identifier, shortened. Spec §48 asks for MSISDNs masked in public UI
     * where practical, and a geofence event names a worker's line.
     */
    public function maskedIdentifier(): ?string
    {
        if (! $this->device_identifier) {
            return null;
        }

        return substr($this->device_identifier, 0, 4).'…'.substr($this->device_identifier, -4);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event_type' => $this->event_type,
            'label' => $this->label(),
            'spec_version' => $this->spec_version,
            'source' => $this->source,
            'provider' => $this->provider,
            'subscription_id' => $this->subscription_id,
            'device' => $this->maskedIdentifier(),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'payload' => $this->payload,
        ];
    }
}
