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
        ];
    }
}
