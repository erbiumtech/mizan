<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3a: what overtime pay has to record to be reproducible.
 *
 * The amount itself goes into `extra_work_hours`, which — despite its name — is
 * already a RUPEE AMOUNT, summed into earnings and debited as `bonus_overtime`. Using
 * it means the ledger posting needs no change at all, and it follows the pattern
 * `advances` and `expense_reimbursement` already set: a figure derived from records
 * when there are records, with an explicit amount still winning because a clerk
 * overriding one month is a legitimate correction.
 *
 * What that column cannot hold is *how* the amount was reached. These three do:
 *
 *  - `overtime_minutes` — what was worked, as attendance recorded it.
 *  - `overtime_hourly_rate` — the derived ordinary rate, recorded because a rate
 *    recomputed next year against a changed package or a changed work pattern would
 *    restate a settled month.
 *  - `overtime_multiplier` — the setting as it stood, for the same reason.
 *
 * Together they make "why was my overtime 12,500" answerable a year later, which a
 * bare amount never could.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            // All nullable, and null on every payslip raised before this and every one
            // raised with payroll.pay_overtime off. Null means "no overtime was
            // computed", which is distinguishable from a computed zero.
            $table->unsignedInteger('overtime_minutes')->nullable()->after('extra_work_hours')
                ->comment('Minutes recorded by attendance for this month');

            $table->decimal('overtime_hourly_rate', 12, 4)->nullable()->after('overtime_minutes')
                ->comment('Derived ordinary rate used. Recorded so a later package change cannot restate this month');

            $table->decimal('overtime_multiplier', 5, 2)->nullable()->after('overtime_hourly_rate')
                ->comment('attendance.overtime_multiplier as it stood when this was calculated');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['overtime_minutes', 'overtime_hourly_rate', 'overtime_multiplier']);
        });
    }
};
