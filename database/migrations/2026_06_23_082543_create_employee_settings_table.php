<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_settings', function (Blueprint $table) {
            $table->id();

            // Relation with Employee Table
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');

            $table->date('start_date')->default(now());
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->date('end_date')->nullable();

            // --- MONTHLY FIXED EARNINGS ---
            $table->decimal('basic_wage', 15, 2)->default(0.00);
            $table->decimal('medical_allowance', 15, 2)->default(0.00);
            $table->decimal('device_allowance', 15, 2)->default(0.00);
            $table->decimal('petrol_allowance', 15, 2)->default(0.00);

            // --- DEFAULTS / EXTRAS ---
            $table->decimal('bonus', 15, 2)->default(0.00);
            $table->decimal('extra_work_hours', 8, 2)->default(0.00);

            // --- MONTHLY FIXED DEDUCTIONS ---
            $table->decimal('advances', 15, 2)->default(0.00)->comment('Advance salary deduction');
            $table->decimal('meal_deduction', 15, 2)->default(0.00);
            $table->decimal('esi_health_insurance', 15, 2)->default(0.00);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_settings');
    }
};
