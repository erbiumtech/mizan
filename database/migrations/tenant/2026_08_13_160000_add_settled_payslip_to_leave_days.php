<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which payslip counted a leave day — docs/hrms-plan.md §5, last bullet, and §10.17.
 *
 * The plan says: *"A locked PayrollRun is closed. Leave approved after sign-off adjusts
 * the next month, and the leave record says which month it was settled in."* Nothing said
 * it, which made the promise unkeepable rather than merely untested.
 *
 * Without this column there are only two possible behaviours for leave approved for a
 * month that has already been signed off, and both are wrong:
 *
 *  - count it in that month anyway — the payslip is locked, so the figure is simply lost;
 *  - count it in the current month every time figures are pulled — so it is counted again
 *    each month for ever.
 *
 * With it, a leave day is counted exactly once, by the payslip that counted it, and an
 * unsettled day belonging to a closed month carries forward to the next open one. That is
 * the whole of the "silently reopening a signed-off run is worse than a late adjustment"
 * rule, made checkable.
 *
 * Nullable, and null on every day generated before this: an unsettled day is the normal
 * resting state, and pro-rating is off by default so most companies will never fill it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_days', function (Blueprint $table) {
            // nullOnDelete rather than cascade: deleting a payslip must return its leave
            // days to the pool so the next month picks them up, not delete somebody's
            // leave record along with a payroll correction.
            $table->foreignId('settled_payslip_id')->nullable()->after('is_paid')
                ->constrained('payslips')->nullOnDelete()
                ->comment('The payslip that counted this day. Null means no payslip has yet.');

            // The read the payroll join makes every month: unsettled days, oldest first.
            $table->index(['settled_payslip_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('leave_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_payslip_id');
        });
    }
};
