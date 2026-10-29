<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every punch a device pushed, raw line kept — docs/saas-launch-plan.md §8.7 —
 * and the PIN the device knows an employee by. Its own column, decided: the
 * employee code is a string that is renumbered and printed; the PIN is a short
 * number enrolled once on the device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_punches', function (Blueprint $table): void {
            $table->id();
            $table->string('serial_no', 64);
            $table->string('device_pin', 32);
            $table->dateTime('punched_at');
            $table->string('status_code', 8)->nullable();
            $table->string('verify_code', 8)->nullable();
            $table->string('raw', 255);
            $table->dateTime('folded_at')->nullable();
            $table->timestamps();

            $table->unique(['serial_no', 'device_pin', 'punched_at'], 'unique_punch_per_device');
            $table->index(['folded_at', 'device_pin']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('device_pin', 32)->nullable()->unique()->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['device_pin']);
            $table->dropColumn('device_pin');
        });

        Schema::dropIfExists('device_punches');
    }
};
