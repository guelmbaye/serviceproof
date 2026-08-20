<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Services\Audit\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->role->canAdministerOrganization(), 403);

        $query = User::query()
            ->when($this->tenant->hasOrganization(), fn ($q) => $q->where('organization_id', $this->tenant->organizationId()))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->orderBy('name');

        return UserResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $organizationId = $request->user()->isSuperAdmin()
            ? $request->input('organization_id', $this->tenant->organizationId())
            : $request->user()->organization_id;

        $user = User::create($request->safe()->merge([
            'organization_id' => $organizationId,
            'status' => 'ACTIVE',
        ])->all());

        $this->audit->log(AuditEventType::USER_CREATED, $user, ['role' => $user->role->value]);

        return new UserResource($user->load('organization'));
    }

    public function update(Request $request, User $user): UserResource
    {
        abort_unless($request->user()->role->canAdministerOrganization(), 403);
        abort_unless($request->user()->belongsToOrganization($user->organization_id), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', 'in:ACTIVE,SUSPENDED'],
            'employee_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'role' => ['sometimes', 'in:'.implode(',', $request->user()->role->assignableRoles())],
        ]);

        $user->fill($data)->save();

        $this->audit->log(AuditEventType::USER_UPDATED, $user, ['changed' => array_keys($data)]);

        return new UserResource($user->load('organization'));
    }
}
