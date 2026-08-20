<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Shared\Enums\AuditEventType;
use App\Domain\Shared\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Services\Audit\AuditLogger;
use App\Services\Organizations\OrganizationProvisioner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationProvisioner $provisioner,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Organization::class);

        return OrganizationResource::collection(
            Organization::withCount(['users', 'workOrders', 'claims'])
                ->orderBy('name')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function show(Organization $organization): OrganizationResource
    {
        $this->authorize('view', $organization);

        return new OrganizationResource($organization->loadCount(['users', 'workOrders', 'claims']));
    }

    public function store(StoreOrganizationRequest $request): OrganizationResource
    {
        $this->authorize('create', Organization::class);

        $organization = DB::transaction(function () use ($request) {
            $organization = $this->provisioner->create($request->validated());

            if ($admin = $request->input('admin')) {
                User::create([
                    'organization_id' => $organization->id,
                    'name' => $admin['name'],
                    'email' => $admin['email'],
                    'password' => $admin['password'],
                    'role' => Role::ORG_ADMIN->value,
                    'status' => 'ACTIVE',
                ]);
            }

            return $organization;
        });

        return new OrganizationResource($organization->loadCount(['users', 'workOrders', 'claims']));
    }

    public function setStatus(Request $request, Organization $organization): OrganizationResource
    {
        $this->authorize('suspend', Organization::class);

        $data = $request->validate(['status' => ['required', 'in:ACTIVE,SUSPENDED']]);

        $organization->forceFill($data)->save();

        $this->audit->log(AuditEventType::ORGANIZATION_STATUS_CHANGED, $organization, $data);

        return new OrganizationResource($organization);
    }
}
