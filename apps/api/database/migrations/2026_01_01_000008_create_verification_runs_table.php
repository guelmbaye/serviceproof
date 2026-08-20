<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('policy_id')->nullable()->constrained('verification_policies')->nullOnDelete();
            $table->foreignUuid('triggered_by')->nullable()->constrained('users')->nullOnDelete();

            // RECEIVED | PLANNING | COLLECTING_EVIDENCE | EVALUATING | ESCALATING | COMPLETED | FAILED
            $table->string('status', 32)->default('RECEIVED');
            $table->string('assurance_level', 16)->default('STANDARD');

            $table->unsignedTinyInteger('budget_max_tool_calls')->default(2);
            $table->unsignedTinyInteger('tool_calls_used')->default(0);
            $table->unsignedInteger('budget_max_latency_ms')->default(12000);
            $table->unsignedInteger('duration_ms')->nullable();

            $table->boolean('escalated')->default(false);
            $table->boolean('used_demo_fallback')->default(false);
            $table->string('agent_version')->nullable();
            $table->string('llm_provider')->nullable();
            $table->string('llm_model')->nullable();
            $table->string('planner_mode', 24)->nullable(); // llm | heuristic | llm_fallback_heuristic

            $table->json('evidence_plan')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('claim_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_runs');
    }
};
