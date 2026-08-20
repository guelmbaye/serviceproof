<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidence is append-only. Once written it is never mutated: a later
     * re-interpretation creates a new assessment, not a rewritten record.
     */
    public function up(): void
    {
        Schema::create('evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('verification_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('claim_id')->constrained()->cascadeOnDelete();

            // LOCATION_VERIFICATION | DEVICE_STATUS | DEVICE_REACHABILITY | ...
            $table->string('type', 48);
            // SUPPORTED | CONFLICTING | UNAVAILABLE | STALE | INVALID
            $table->string('status', 24);

            $table->string('source', 32);                 // CAMARA | DEMO_FALLBACK
            $table->string('provider', 48)->nullable();   // NOKIA_NAC
            $table->string('api_name')->nullable();       // Location Verification
            $table->string('request_id')->nullable();
            $table->string('freshness', 16)->default('CURRENT'); // CURRENT | STALE | UNKNOWN
            $table->unsignedInteger('age_seconds')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('reliability', 4, 3)->nullable();

            $table->timestamp('observed_at')->nullable(); // when the network observed it
            $table->timestamp('received_at');             // when ServiceProof received it

            $table->string('summary')->nullable();        // human-readable, no raw telecom payload
            $table->json('normalized')->nullable();       // normalized contract
            $table->string('payload_hash', 64)->nullable();
            $table->json('raw_reference')->nullable();    // debug only, data-minimised
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['verification_run_id', 'type']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};
