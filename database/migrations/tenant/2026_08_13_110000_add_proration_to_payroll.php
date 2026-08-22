<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: what pay pro-rating needs before it can be switched on safely.
 *
 * Three columns, each of which exists because of a specific way this goes wrong:
 *
 *  - **`pay_components.prorates`.** Which components scale is per component, not
 *    all-or-nothing. A basic wage pro-rates; a fixed medical or device allowance
 *    usually does not; a deduction never does by attendance. `is_taxable` already
 *    proves the pattern — a component knows things about itself.
 *
 *  - **`payslips.proration_divisor` and `proration_basis_days`.** The divisor used is
 *    recorded ON THE PAYSLIP, not read back from the setting. Otherwise a company that
 *    changes `payroll.proration_divisor` next year silently restates every settled
 *    month the next time a payslip is saved — and payslips are re-saved routinely,
 *    because Payslip::booted() recalculates on every update. This is the same rule
 *    leave_days follows: a setting decides what happens next, never what already
 *    happened.
 *
 * Nothing here changes any figure. The switch that does is `payroll.prorate_on_attendance`,
 * and it defaults OFF — a behaviour that moves money must not change under a company
 * that did not ask for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_components', function (Blueprint $table) {
            // Defaults FALSE, which is the conservative direction: an existing
            // component keeps paying in full until somebody says it should scale.
            // Defaulting true would have quietly reduced every allowance in the first
            // month a company switched pro-rating on.
            $table->boolean('prorates')->default(false)->after('is_taxable')
                ->comment('Whether attendance pro-rating scales this component. Deductions never do.');
        });

        Schema::table('payslips', function (Blueprint $table) {
            // Null on every payslip that was not pro-rated, which is every payslip
            // raised before this and every one raised with the switch off. Null means
            // "paid in full", and is distinguishable from a divisor of zero.
            $table->string('proration_divisor')->nullable()->after('leaves_taken')
                ->comment('working_days|calendar_days|fixed_26|fixed_30 — the rule used, recorded so a later setting change cannot restate this month');

            $table->decimal('proration_basis_days', 5, 1)->nullable()->after('proration_divisor')
                ->comment('The number actually divided by. Kept beside the rule because the rule alone does not reproduce the arithmetic.');
        });
    }

    public function down(): void
    {
        Schema::table('pay_components', function (Blueprint $table) {
            $table->dropColumn('prorates');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['proration_divisor', 'proration_basis_days']);
        });
    }
};
