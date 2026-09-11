<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CloudEvents pushed to us by the operator.
     *
     * A separate table from `evidence`, deliberately and permanently. Evidence
     * is something ServiceProof asked for, received over an authenticated
     * channel, and normalised through a tool it controls. These arrive
     * unsolicited on a public endpoint that anyone who learns the URL can post
     * to, and Nokia signs nothing.
     *
     * Putting them in `evidence` would mean an unauthenticated stranger could
     * write a row the decision path reads — which is the precise failure this
     * product argues against. They are network events we observed. They are
     * never evidence, they enter no verification, and no decision reads them.
     */
    public function up(): void
    {
        Schema::create('network_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // CloudEvents 1.0 envelope, as delivered.
            $table->string('event_id', 128)->nullable();
            $table->string('event_type', 128);
            $table->string('spec_version', 16)->nullable();
            $table->string('source', 255)->nullable();
            $table->timestampTz('occurred_at')->nullable();

            // What the event is about, lifted out for display and filtering.
            $table->string('subscription_id', 128)->nullable();
            $table->string('device_identifier', 64)->nullable();

            $table->string('provider', 48)->default('NOKIA_NAC');

            // The raw body exactly as posted. Never edited: if our reading of
            // a field turns out wrong, the record still holds what arrived.
            $table->json('payload');

            $table->timestampTz('received_at');
            $table->timestamps();

            $table->index(['event_type', 'received_at']);
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_events');
    }
};
