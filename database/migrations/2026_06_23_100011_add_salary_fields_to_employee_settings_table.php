<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_settings', function (Blueprint $table) {
            $table->decimal('basic_wage', 10, 2)->default(0.00);
            $table->decimal('medical_allowance', 10, 2)->default(0.00);
            $table->decimal('device_allowance', 10, 2)->default(0.00);
            $table->decimal('petrol_allowance', 10, 2)->default(0.00);
            $table->decimal('meal_deduction', 10, 2)->default(0.00);
            $table->decimal('esi_health_insurance', 10, 2)->default(0.00);
        });
    }

    public function down(): void
    {
        Schema::table('employee_settings', function (Blueprint $table) {
            $table->dropColumn([
                'basic_wage', 'medical_allowance', 'device_allowance',
                'petrol_allowance', 'meal_deduction', 'esi_health_insurance'
            ]);
        });
    }
};
