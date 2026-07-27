<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_environments', function (Blueprint $table) {
            $table->boolean('alerts_enabled')->default(true)->after('is_monitored');
            $table->timestamp('muted_until')->nullable()->after('alerts_enabled')
                ->comment('Maintenance window: suppresses alerts, still records checks');
            $table->unsignedSmallInteger('check_interval_min')->nullable()->after('muted_until')
                ->comment('Null = config default');
            $table->string('expected_content')->nullable()->after('check_interval_min')
                ->comment('When set the check GETs and asserts the body contains this');
            $table->unsignedSmallInteger('expected_status')->nullable()->after('expected_content');
            $table->boolean('is_public')->default(false)->after('expected_status')
                ->comment('Publishable on the status page');

            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->unsignedSmallInteger('consecutive_successes')->default(0);

            $table->timestamp('ssl_expires_at')->nullable();
            $table->string('ssl_issuer')->nullable();
            $table->timestamp('ssl_checked_at')->nullable();
            $table->boolean('ssl_valid_chain')->nullable();
            $table->unsignedSmallInteger('ssl_alerted_at_days')->nullable()
                ->comment('Last expiry threshold already alerted on');
        });
    }

    public function down(): void
    {
        Schema::table('project_environments', function (Blueprint $table) {
            $table->dropColumn([
                'alerts_enabled', 'muted_until', 'check_interval_min',
                'expected_content', 'expected_status', 'is_public',
                'consecutive_failures', 'consecutive_successes',
                'ssl_expires_at', 'ssl_issuer', 'ssl_checked_at', 'ssl_valid_chain', 'ssl_alerted_at_days',
            ]);
        });
    }
};
