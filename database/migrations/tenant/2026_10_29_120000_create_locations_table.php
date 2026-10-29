<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches — docs/saas-launch-plan.md §7.9, the shape docs/hrms-plan.md §3 wrote
 * down for "when an employee-bearing location concept lands in the schema".
 *
 * Core, by the precedent that put FiscalYear and Holiday there: Employees,
 * Attendance, Leave and Recruitment read it and none owns it. `location_id` is
 * nullable everywhere — on an employee it means "no site recorded", on a holiday
 * it means company-wide, exactly as the plan foresaw — so every existing row
 * keeps its meaning. The geofence radius is nullable too: a location without
 * one is a place, not a fence.
 *
 * `outside_geofence` on a punch is the flag mode's mark (plan §7.9), null for
 * every punch made before this or without a reading to judge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('geofence_radius_m')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('department')->constrained('locations')->nullOnDelete();
        });

        Schema::table('holidays', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('name')->constrained('locations')->nullOnDelete();
        });

        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->boolean('outside_geofence')->nullable()->after('accuracy_m');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->dropColumn('outside_geofence');
        });

        Schema::table('holidays', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::dropIfExists('locations');
    }
};
