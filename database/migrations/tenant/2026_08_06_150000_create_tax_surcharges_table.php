<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The surcharge under section 4AB: a percentage of the *tax*, not of the income,
 * once taxable income passes a threshold.
 *
 * Its own table rather than columns on tax_schedules because it is one rule per
 * (year, regime) while a schedule is many brackets per (year, regime) — putting
 * it on the brackets would repeat it six times and invite the copies to disagree.
 *
 * A row's absence means no surcharge, which is what makes the Finance Act 2026
 * withdrawing it for salaried expressible as data rather than a code branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_surcharges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->string('regime');

            $table->decimal('threshold', 15, 2)
                ->comment('Taxable income above which the surcharge applies');
            $table->decimal('percentage', 5, 2)
                ->comment('Percentage OF THE TAX, not of the income');

            $table->timestamps();

            $table->unique(['fiscal_year_id', 'regime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_surcharges');
    }
};
