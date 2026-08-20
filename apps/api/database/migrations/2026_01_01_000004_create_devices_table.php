<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devices are the bridge between a person and a network identifier.
     * The raw network identifier is treated as sensitive: the UI shows
     * `reference` (DEV-001), never the MSISDN.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference');                 // DEV-001 — safe to display
            $table->string('label')->nullable();
            $table->string('identifier_type', 32)->default('PHONE_NUMBER'); // PHONE_NUMBER | NETWORK_ACCESS_IDENTIFIER | IPV4 | IPV6
            $table->string('network_identifier');        // sensitive, server-side only
            $table->boolean('is_simulator')->default(true);
            $table->string('status', 32)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
