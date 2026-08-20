<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Observable action trace — NOT model chain-of-thought.
     * Every row answers "what did the system do, and why was that step taken".
     */
    public function up(): void
    {
        Schema::create('agent_trace_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('verification_run_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');
            // AGENT_STARTED | PLAN_CREATED | TOOL_SELECTED | TOOL_CALLED |
            // EVIDENCE_RECEIVED | EVIDENCE_CONFLICT | ESCALATION_REQUIRED |
            // BUDGET_EXHAUSTED | POLICY_EVALUATED | DECISION_PROPOSED |
            // GUARD_APPLIED | AGENT_COMPLETED | AGENT_FAILED
            $table->string('event_type', 48);
            $table->string('label');                    // one-line, demo friendly
            $table->json('detail')->nullable();
            $table->timestamp('occurred_at', 3);
            $table->timestamps();

            $table->unique(['verification_run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_trace_events');
    }
};
