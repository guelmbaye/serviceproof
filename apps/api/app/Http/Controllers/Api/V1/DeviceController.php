<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Devices\Models\Device;
use App\Domain\Shared\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Identity mapping between a field worker, a ServiceProof device reference
 * and the network identifier used by the CAMARA adapters.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Device::class);

        return DeviceResource::collection(
            Device::with('user')->orderBy('reference')->paginate($request->integer('per_page', 50))
        );
    }

    public function store(StoreDeviceRequest $request): DeviceResource
    {
        $device = Device::create($request->safe()->merge([
            'organization_id' => $request->user()->organization_id,
            'status' => 'ACTIVE',
        ])->all());

        $this->audit->log(AuditEventType::ADMIN_ACTION, $device, [
            'action' => 'DEVICE_REGISTERED',
            'reference' => $device->reference,
            'is_simulator' => $device->is_simulator,
        ]);

        return new DeviceResource($device->load('user'));
    }

    public function update(Request $request, Device $device): DeviceResource
    {
        $this->authorize('manage', $device);

        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'user_id' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
            'network_identifier' => ['sometimes', 'string', 'max:180'],
            'identifier_type' => ['sometimes', 'in:PHONE_NUMBER,NETWORK_ACCESS_IDENTIFIER,IPV4,IPV6'],
        ]);

        $device->fill($data)->save();

        $this->audit->log(AuditEventType::ADMIN_ACTION, $device, [
            'action' => 'DEVICE_UPDATED',
            'changed' => array_keys($data),
        ]);

        return new DeviceResource($device->load('user'));
    }
}
