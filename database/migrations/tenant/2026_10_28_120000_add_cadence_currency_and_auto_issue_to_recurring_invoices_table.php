<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three things a recurring agreement could not say — docs/saas-launch-plan.md §3.1, §6.
 *
 *  - `every_months`   raise every N months rather than every month. 1 is every
 *                     existing agreement, unchanged.
 *  - `currency_code`  the invoice's currency; null is the base, as on invoices.
 *  - `auto_issue`     the raised draft may be issued by a job, unseen. False for
 *                     every existing agreement: "a person issues it" stays the rule
 *                     for everyone, and only the platform's Subscribe action sets
 *                     this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table): void {
            $table->unsignedTinyInteger('every_months')->default(1)->after('day_of_month');
            $table->char('currency_code', 3)->nullable()->after('every_months');
            $table->boolean('auto_issue')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table): void {
            $table->dropColumn(['every_months', 'currency_code', 'auto_issue']);
        });
    }
};
