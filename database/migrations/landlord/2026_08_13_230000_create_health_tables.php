<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\ResultStores\EloquentHealthResultStore;

/**
 * Health check history — **landlord, not tenant**, and moved here from where the package put it.
 *
 * `vendor:publish --tag=health-migrations` writes to `database/migrations/`, which this
 * repository does not use: everything lives under `landlord/` or `tenant/`, and
 * `AppServiceProvider` loads exactly those two. A migration left at the root would still run
 * (Laravel's default path) but against no stated connection, which is precisely the ambiguity
 * the split exists to remove.
 *
 * It belongs to the landlord because **health is a fact about the installation, not about a
 * company**. Is the disk full, is Redis answering, is the scheduler running, did the backup
 * finish — none of those are per-tenant questions, and none of them have forty different
 * answers. Per-tenant history would also mean the check that matters most, "can this company's
 * database be reached at all", could only record its answer in the database it just failed to
 * reach.
 */
return new class extends Migration
{
    public function up()
    {
        $connection = (new HealthCheckResultHistoryItem)->getConnectionName();
        $tableName = EloquentHealthResultStore::getHistoryItemInstance()->getTable();

        Schema::connection($connection)->create($tableName, function (Blueprint $table) {
            $table->id();

            $table->string('check_name');
            $table->string('check_label');
            $table->string('status');
            $table->text('notification_message')->nullable();
            $table->string('short_summary')->nullable();
            $table->json('meta');
            $table->timestamp('ended_at');
            $table->uuid('batch');

            $table->timestamps();
        });

        Schema::connection($connection)->table($tableName, function (Blueprint $table) {
            $table->index('created_at');
            $table->index('batch');
        });
    }
};
