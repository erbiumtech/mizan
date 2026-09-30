<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\ResultStores\EloquentHealthResultStore;

/**
 * The table spatie/laravel-health's Eloquent store writes to — which was never
 * published. The check *classes* and the schedule shipped, `config/health.php`
 * names EloquentHealthResultStore, but its migration is a vendor `.stub` and the
 * app loads only its own landlord/tenant paths, so the table did not exist. The
 * store had nowhere to persist, and anything reading it back — the /ops/health
 * page, the dashboard HealthOverview widget — queried a table that was not there.
 *
 * Landlord, not tenant: health is installation-wide (one run covers every
 * company), and the store's connection is the default one, which here is the
 * landlord. `Schema::connection()` from the stub is kept so an explicit
 * HEALTH_DB_CONNECTION still decides where it lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = (new HealthCheckResultHistoryItem)->getConnectionName();
        $table = EloquentHealthResultStore::getHistoryItemInstance()->getTable();

        if (Schema::connection($connection)->hasTable($table)) {
            return;
        }

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
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

            $table->index('created_at');
            $table->index('batch');
        });
    }

    public function down(): void
    {
        $connection = (new HealthCheckResultHistoryItem)->getConnectionName();
        Schema::connection($connection)->dropIfExists(
            EloquentHealthResultStore::getHistoryItemInstance()->getTable(),
        );
    }
};
