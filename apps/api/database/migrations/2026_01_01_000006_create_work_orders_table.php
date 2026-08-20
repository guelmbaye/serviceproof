<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference');                 // WO-1042
            $table->string('customer_name');
            $table->string('service_type')->nullable();
            $table->text('description')->nullable();

            // Expected site — the operational truth the claim is checked against.
            $table->string('site_name');
            $table->string('site_address')->nullable();
            $table->decimal('site_latitude', 10, 7);
            $table->decimal('site_longitude', 10, 7);
            $table->unsignedInteger('site_radius_m')->default(1000);

            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignUuid('policy_id')->nullable()->constrained('verification_policies')->nullOnDelete();

            $table->string('risk_level', 16)->default('NORMAL'); // LOW | NORMAL | HIGH
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('window_starts_at')->nullable();
            $table->timestamp('window_ends_at')->nullable();
            $table->string('status', 32)->default('SCHEDULED');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status']);
            $table->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
