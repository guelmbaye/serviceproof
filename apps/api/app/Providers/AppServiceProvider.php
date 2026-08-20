<?php

namespace App\Providers;

use App\Domain\Claims\Models\Claim;
use App\Domain\Devices\Models\Device;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Reviews\Models\Review;
use App\Domain\Verification\Models\VerificationRun;
use App\Domain\WorkOrders\Models\WorkOrder;
use App\Policies\ClaimPolicy;
use App\Policies\DevicePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ReviewPolicy;
use App\Policies\VerificationPolicyPolicy;
use App\Policies\VerificationRunPolicy;
use App\Policies\WorkOrderPolicy;
use App\Services\Agent\AgentGateway;
use App\Services\Agent\FakeAgentClient;
use App\Services\Agent\HttpAgentClient;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);

        // Note on Sanctum: ServiceProof ships its own personal_access_tokens
        // migration because tokenable_id has to be a UUID, not a bigint. From
        // Sanctum 4 that needs no opt-out — the package only *publishes* its
        // migration and never loads it, so ours is the only one that runs.
        // (Sanctum 3 needed Sanctum::ignoreMigrations() here; that method no
        // longer exists.)

        // The agent runtime is swappable behind a single interface: HTTP in
        // normal operation, in-process fake for the test suite.
        $this->app->bind(AgentGateway::class, function () {
            return config('services.agent.fake')
                ? new FakeAgentClient
                : new HttpAgentClient;
        });
    }

    public function boot(): void
    {
        Model::unguard(false);

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);
        Gate::policy(Claim::class, ClaimPolicy::class);
        Gate::policy(Review::class, ReviewPolicy::class);
        Gate::policy(VerificationRun::class, VerificationRunPolicy::class);
        Gate::policy(VerificationPolicy::class, VerificationPolicyPolicy::class);
        Gate::policy(Device::class, DevicePolicy::class);

        // A SUPER_ADMIN operates the platform itself. The bypass is explicit
        // and every action they take is written to the audit log.
        Gate::before(fn ($user) => $user->isSuperAdmin() ? true : null);
    }
}
