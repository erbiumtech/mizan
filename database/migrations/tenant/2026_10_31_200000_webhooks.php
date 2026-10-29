<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing webhooks — docs/saas-launch-plan.md §14.9.
 *
 * Per tenant, because an endpoint is a company's integration with its own systems
 * and the events are about its own records.
 *
 * The delivery log is the feature, not a byproduct. A webhook that fails silently is
 * worse than one that was never configured: the receiving system goes on believing
 * it is in step. Every attempt is a row, including the ones that failed and the ones
 * still to be retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('url');
            // The shared secret every delivery to this endpoint is signed with. Shown once,
            // on creation, for the same reason an API key is.
            $table->string('secret', 64);
            $table->json('events');
            $table->boolean('is_active')->default(true);
            // Switched off after enough consecutive failures, so a dead endpoint stops
            // costing a queue worker a retry schedule per event forever.
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('event', 80);
            $table->json('payload');
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
