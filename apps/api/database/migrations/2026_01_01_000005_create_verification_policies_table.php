<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key');                       // STANDARD_FIELD_SERVICE
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('assurance_level', 16)->default('STANDARD'); // LOW | STANDARD | HIGH
            $table->json('required_evidence');
            $table->json('optional_evidence')->nullable();
            $table->unsignedTinyInteger('max_tool_calls')->default(2);
            $table->unsignedInteger('max_latency_ms')->default(12000);
            $table->unsignedInteger('location_radius_m')->default(1000);
            $table->unsignedInteger('freshness_seconds')->default(900);
            $table->boolean('allow_partial')->default(true);
            $table->boolean('auto_close_on_verified')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_policies');
    }
};
