<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pakistani income tax schedules, one row per bracket, per regime, per tax year.
 *
 * Same bracket arithmetic as payroll's `salary_slabs` — `min_amount` is the
 * *exceeding* threshold and `fixed_tax` is the cumulative tax of every bracket
 * below it, so tax is `fixed_tax + percentage% x (income - min_amount)`. That
 * representation is proven and worth copying.
 *
 * A separate table rather than a `regime` column on `salary_slabs`, because
 * TaxCalculatorService queries that table on `fiscal_year_id` alone: adding a
 * regime there without also changing the query would have payroll picking up
 * business-income brackets and mis-taxing employees. Payroll's table is left
 * exactly as it is.
 *
 * `max_amount` null means the top bracket, and it must be null on the highest
 * one — a bounded top bracket is how the payroll seeder came to tax its highest
 * earners at zero. PersonalTaxService raises rather than returning zero when
 * nothing matches, so the same mistake surfaces here instead of hiding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');

            $table->enum('regime', ['salaried', 'business', 'rental', 'capital_gains']);

            $table->decimal('min_amount', 15, 2);
            $table->decimal('max_amount', 15, 2)->nullable()->comment('Null means the top bracket');
            $table->decimal('fixed_tax', 15, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);

            $table->timestamps();

            $table->unique(['fiscal_year_id', 'regime', 'min_amount']);
            $table->index(['fiscal_year_id', 'regime']);
        });

        Schema::create('personal_tax_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->index(); // soft ref -> landlord users
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');

            // Recorded and shown, not applied to the slab result. Filer status
            // changes *withholding* rates rather than the liability the brackets
            // produce, so silently adjusting the estimate by it would be wrong.
            $table->enum('filer_status', ['filer', 'non_filer'])->default('filer');

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_tax_profiles');
        Schema::dropIfExists('tax_schedules');
    }
};
