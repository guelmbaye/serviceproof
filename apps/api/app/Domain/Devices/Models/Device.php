<?php

namespace App\Domain\Devices\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identity mapping: Field Worker -> ServiceProof device reference -> CAMARA identifier.
 * `network_identifier` is sensitive and is never serialised to a client.
 */
class Device extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id', 'user_id', 'reference', 'label',
        'identifier_type', 'network_identifier', 'is_simulator', 'status',
    ];

    protected $hidden = ['network_identifier'];

    protected function casts(): array
    {
        return ['is_simulator' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The device descriptor handed to the agent runtime. Shaped like the
     * CAMARA `device` object so the adapter layer stays thin.
     */
    public function toNetworkDescriptor(): array
    {
        $key = match ($this->identifier_type) {
            'NETWORK_ACCESS_IDENTIFIER' => 'networkAccessIdentifier',
            'IPV4' => 'ipv4Address',
            'IPV6' => 'ipv6Address',
            default => 'phoneNumber',
        };

        return [
            'reference' => $this->reference,
            'identifier_type' => $this->identifier_type,
            'identifier_key' => $key,
            'identifier' => $this->network_identifier,
            'is_simulator' => (bool) $this->is_simulator,
            'entitlement' => $this->networkEntitlement(),
        ];
    }

    /**
     * Whether this organisation may currently ask the operator about this
     * device. The agent refuses to make any CAMARA call without it.
     *
     * Derived from the device record rather than from a separate consent
     * store, because a separate store would imply a consent lifecycle this
     * build does not have. A device belongs to one organisation and one
     * worker, and an ACTIVE device is one the organisation has registered and
     * not retired. That is a thin entitlement and it is described as one.
     *
     * What it deliberately is not: per-jurisdiction lawful basis, worker-facing
     * disclosure, or revocation with an audit trail. Those are scoped and
     * unbuilt, and inventing a richer model here would claim a compliance
     * story the code does not deliver.
     */
    public function networkEntitlement(): array
    {
        return [
            'status' => $this->status === 'ACTIVE' ? 'ACTIVE' : (string) $this->status,
            'reference' => "BIND-{$this->reference}",
            'purpose' => 'FIELD_SERVICE_ASSURANCE',
            'granted_at' => $this->created_at?->toIso8601String(),
            'expires_at' => null,

            // Named capabilities, not a blanket permission. The agent refuses
            // any tool whose evidence type is absent from this list, before
            // the planner can even see it — so permission to ask where a
            // device is never silently extends to asking whether its SIM
            // changed.
            'allowed_capabilities' => config(
                'serviceproof.entitlement.default_capabilities',
                ['LOCATION_VERIFICATION', 'DEVICE_SWAP']
            ),
        ];
    }
}
