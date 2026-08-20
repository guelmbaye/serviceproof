<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A claim is an assertion, never evidence.
     */
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference');                 // CLM-1042
            $table->string('claim_type', 48)->default('SERVICE_COMPLETED');
            $table->timestamp('claimed_at');
            $table->text('notes')->nullable();           // untrusted user text
            $table->json('context')->nullable();         // app-reported context (never evidence)
            $table->string('status', 32)->default('SUBMITTED');
            $table->string('idempotency_key')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'status']);
            $table->index('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
