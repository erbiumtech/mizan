<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('annual_taxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('fiscal_year_id');
            $table->decimal('total_net_income', 12, 2)->default(0);
            $table->decimal('annual_income_tax', 12, 2)->default(0);
            $table->decimal('total_annual_tax', 12, 2)->default(0);
            $table->decimal('paid_tax', 12, 2)->default(0);
            $table->decimal('leftover_tax', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['employee_id', 'fiscal_year_id'], 'unique_emp_fiscal_tax');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('annual_taxes');
    }
};
