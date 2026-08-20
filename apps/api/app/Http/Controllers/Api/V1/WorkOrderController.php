<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Enums\AuditEventType;
use App\Domain\Shared\Enums\Role;
use App\Domain\WorkOrders\Models\WorkOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Http\Resources\WorkOrderResource;
use App\Services\Audit\AuditLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class WorkOrderController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkOrder::class);

        $query = WorkOrder::query()
            ->with(['assignedUser', 'device', 'policy'])
            ->when(
                $request->user()->role === Role::FIELD_WORKER,
                fn ($q) => $q->assignedTo($request->user()->id)
            )
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('open_only'), fn ($q) => $q->open())
            ->when($request->filled('assigned_user_id'), fn ($q) => $q->where('assigned_user_id', $request->string('assigned_user_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower($request->string('search')->toString()).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->whereRaw('LOWER(reference) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(customer_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(site_name) LIKE ?', [$term]);
                });
            })
            ->orderByRaw('COALESCE(scheduled_at, created_at) DESC');

        return WorkOrderResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(WorkOrder $workOrder): WorkOrderResource
    {
        $this->authorize('view', $workOrder);

        return new WorkOrderResource(
            $workOrder->load(['assignedUser', 'device', 'policy', 'claims.currentDecision', 'claims.user'])
        );
    }

    public function store(StoreWorkOrderRequest $request): WorkOrderResource
    {
        $this->authorize('create', WorkOrder::class);

        $workOrder = DB::transaction(function () use ($request) {
            $organizationId = $request->user()->organization_id ?? $request->header('X-Organization-Id');

            return WorkOrder::create(array_merge($request->validated(), [
                'organization_id' => $organizationId,
                'reference' => ReferenceGenerator::next('work_orders', $organizationId, 'WO'),
                'site_radius_m' => $request->integer('site_radius_m', 1000),
                'risk_level' => $request->input('risk_level', 'NORMAL'),
                'status' => 'SCHEDULED',
            ]));
        });

        $this->audit->log(AuditEventType::WORK_ORDER_CREATED, $workOrder, ['reference' => $workOrder->reference]);

        return new WorkOrderResource($workOrder->load(['assignedUser', 'device', 'policy']));
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        $this->authorize('update', $workOrder);

        $workOrder->fill($request->validated())->save();

        $this->audit->log(AuditEventType::WORK_ORDER_UPDATED, $workOrder, [
            'changed' => array_keys($request->validated()),
        ]);

        return new WorkOrderResource($workOrder->load(['assignedUser', 'device', 'policy']));
    }
}
