<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('verification_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('claim_id')->constrained()->cascadeOnDelete();

            // VERIFIED | PARTIAL | DISPUTED | UNVERIFIED
            $table->string('state', 24);
            $table->string('recommended_action', 48);  // CLOSE | REVIEW | ESCALATE | MANUAL_VERIFICATION
            $table->unsignedTinyInteger('assurance_score')->nullable(); // 0-100, explainable
            $table->json('assurance_breakdown')->nullable();
            $table->text('rationale')->nullable();

            $table->string('origin', 24)->default('AGENT'); // AGENT | POLICY_GUARD | HUMAN
            $table->boolean('policy_satisfied')->default(false);
            $table->boolean('guard_applied')->default(false);
            $table->string('guard_reason')->nullable();
            $table->boolean('simulated')->default(false);   // any DEMO_FALLBACK evidence involved

            $table->boolean('is_current')->default(true);

            // A superseded decision points at the one that replaced it. The
            // foreign key is added below rather than here — see the note.
            $table->uuid('superseded_by')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'state']);
            $table->index('claim_id');
        });

        // Self-referencing foreign key, added in a second statement on purpose.
        //
        // Laravel appends the implied `primary key` command *after* the foreign
        // key commands it collected from ->constrained(). For a table that
        // references itself that ordering is fatal on PostgreSQL: the ALTER
        // adding the key runs before decisions.id has a unique constraint, and
        // the server rejects it with SQLSTATE 42830. Splitting it out means the
        // primary key already exists by the time this runs.
        Schema::table('decisions', function (Blueprint $table) {
            $table->foreign('superseded_by')
                ->references('id')
                ->on('decisions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};
