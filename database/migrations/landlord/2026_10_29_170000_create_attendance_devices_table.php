<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device registry — docs/saas-launch-plan.md §8.7. Landlord-side because the
 * device identifies itself by serial before any company is current, the way a
 * signup does by email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('serial_no', 64)->unique();
            $table->string('name', 80);
            $table->string('model', 80)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('silence_notified_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_devices');
    }
};
