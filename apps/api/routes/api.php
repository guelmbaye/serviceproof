<?php

use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClaimController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\EvidenceController;
use App\Http\Controllers\Api\V1\PolicyController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SuperAdmin\OrganizationController;
use App\Http\Controllers\Api\V1\SuperAdmin\PlatformController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Api\V1\WorkOrderController;
use App\Http\Controllers\Internal\AgentEventController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ServiceProof API — v1
|--------------------------------------------------------------------------
| Boundary rules:
|   Browser  -> Laravel      Flutter -> Laravel
|   Laravel  -> FastAPI      FastAPI -> Nokia NaC / CAMARA
|
| Nothing else is permitted. No client ever reaches the agent runtime or a
| network API directly, and no CAMARA credential exists outside the backend.
*/

Route::prefix('v1')->group(function () {

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'org.context'])->group(function () {

        // ── Session ───────────────────────────────────────────────────────
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        // ── Operations dashboard ──────────────────────────────────────────
        Route::get('dashboard', [DashboardController::class, 'overview'])->name('dashboard.overview');
        Route::get('dashboard/roi', [DashboardController::class, 'roi'])->name('dashboard.roi');

        // ── Work orders ───────────────────────────────────────────────────
        Route::get('work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
        Route::post('work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
        Route::get('work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('work-orders.show');
        Route::patch('work-orders/{workOrder}', [WorkOrderController::class, 'update'])->name('work-orders.update');

        // Field workflow: the mobile app submits here.
        Route::post('work-orders/{workOrder}/claims', [ClaimController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('work-orders.claims.store');

        // ── Claims & verification ─────────────────────────────────────────
        Route::get('claims', [ClaimController::class, 'index'])->name('claims.index');
        Route::get('claims/{claim}', [ClaimController::class, 'show'])->name('claims.show');
        Route::post('claims/{claim}/verify', [ClaimController::class, 'verify'])
            ->middleware('throttle:30,1')
            ->name('claims.verify');
        Route::get('claims/{claim}/evidence', [EvidenceController::class, 'forClaim'])->name('claims.evidence');

        Route::get('verifications', [VerificationController::class, 'index'])->name('verifications.index');
        Route::get('verifications/health', [VerificationController::class, 'health'])->name('verifications.health');
        Route::get('verifications/{verification}', [VerificationController::class, 'show'])->name('verifications.show');
        Route::get('verifications/{verification}/trace', [VerificationController::class, 'trace'])->name('verifications.trace');

        // ── Evidence explorer ─────────────────────────────────────────────
        Route::get('evidence', [EvidenceController::class, 'index'])->name('evidence.index');
        Route::get('evidence/{evidence}', [EvidenceController::class, 'show'])->name('evidence.show');

        // ── Review queue ──────────────────────────────────────────────────
        Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
        Route::get('reviews/{review}', [ReviewController::class, 'show'])->name('reviews.show');
        Route::post('reviews/{review}/assign', [ReviewController::class, 'assign'])->name('reviews.assign');
        Route::post('reviews/{review}/resolve', [ReviewController::class, 'resolve'])->name('reviews.resolve');

        // ── Organisation administration ───────────────────────────────────
        Route::get('policies', [PolicyController::class, 'index'])->name('policies.index');
        Route::get('policies/{policy}', [PolicyController::class, 'show'])->name('policies.show');
        Route::patch('policies/{policy}', [PolicyController::class, 'update'])->name('policies.update');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');

        Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::patch('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');

        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

        // ── Super admin (platform) ────────────────────────────────────────
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
            Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
            Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
            Route::patch('organizations/{organization}/status', [OrganizationController::class, 'setStatus'])->name('organizations.status');

            Route::get('health', [PlatformController::class, 'health'])->name('health');
            Route::get('stats', [PlatformController::class, 'stats'])->name('stats');
        });
    });
});

/*
| Internal channel — Laravel <- FastAPI agent runtime.
| Shared-secret authenticated, never exposed publicly.
*/
Route::prefix('internal')->middleware('agent.internal')->group(function () {
    Route::post('agent/trace', [AgentEventController::class, 'store'])->name('internal.agent.trace');
});
