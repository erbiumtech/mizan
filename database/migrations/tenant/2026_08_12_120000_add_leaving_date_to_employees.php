<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When someone left, on `employees` rather than in `lifecycle`.
     *
     * The leaving date is a fact about the employee and payroll must read it —
     * a final settlement, a gratuity span, a payslip that must not be generated
     * for a month after the last day — whether or not the company ever buys the
     * lifecycle module. See docs/hrms-plan.md §3.
     *
     * `is_active` is deliberately untouched. It is the flag every existing query
     * already filters on, and nothing here changes that; `left_on` records *when*
     * it happened, not *whether*. Making the date the new source of truth would
     * mean auditing every `where('is_active', …)` in the codebase for a benefit
     * nobody asked for.
     *
     * NO BACKFILL, on purpose. Employees already inactive keep `left_on` null.
     * We do not know when they left — the row never recorded it — and any date we
     * derived (updated_at, the last payslip's month) would read as a fact to
     * everyone downstream, including a gratuity calculation. Null is honest and
     * queryable; a guess is neither.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('left_on')->nullable()->after('is_active');

            // Free string rather than an enum: the reasons are
            // resigned|terminated|contract_end|retired|deceased today, and a
            // company that needs a sixth should not need a migration to get it.
            // Enum changes are a table rebuild on MySQL and unsupported on SQLite.
            $table->string('leaving_reason')->nullable()->after('left_on');

            // Notice period end, which is often *not* `left_on`: someone can be
            // paid through a notice they do not work, and payroll needs both
            // dates to decide what the final month owes.
            $table->date('notice_served_until')->nullable()->after('leaving_reason');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['left_on', 'leaving_reason', 'notice_served_until']);
        });
    }
};
