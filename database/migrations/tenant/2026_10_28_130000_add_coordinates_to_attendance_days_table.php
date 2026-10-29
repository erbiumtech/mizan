<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a self-service punch was made — docs/saas-launch-plan.md §7.1.
 *
 * The browser's or the phone's Geolocation reading at clock-in, recorded and not
 * judged: null when the device refused or had no fix, and nothing here decides
 * anything from it. A fence around a location is §7.9, and it reads these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('check_out_at');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('accuracy_m')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude', 'accuracy_m']);
        });
    }
};
