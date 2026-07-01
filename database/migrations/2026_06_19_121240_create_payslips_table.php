<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            // Relation with Employee
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');

            // Month aur Year (e.g., October 2024)
            $table->string('pay_period');

            // --- Attendance ---
            $table->integer('total_working_days')->default(0);
            $table->integer('paid_days')->default(0);
            $table->integer('lop_days')->default(0);
            $table->integer('leaves_taken')->default(0);

            // --- Earnings ---
            $table->decimal('basic_wage', 10, 2)->default(0);
            $table->decimal('medical_allowance', 10, 2)->default(0);
            $table->decimal('device_allowance', 10, 2)->default(0);
            $table->decimal('petrol_allowance', 10, 2)->default(0);
            $table->decimal('extra_work_hours', 10, 2)->default(0);
            $table->decimal('bonus', 10, 2)->default(0);

            // --- Deductions ---
            $table->decimal('withholding_tax', 10, 2)->default(0);
            $table->decimal('advances', 10, 2)->default(0);
            $table->decimal('meal_deduction', 10, 2)->default(0);
            $table->decimal('esi_health_insurance', 10, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('payslips');
    }
};
