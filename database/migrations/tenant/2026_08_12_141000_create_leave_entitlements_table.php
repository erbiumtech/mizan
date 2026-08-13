<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one employee is entitled to, of one leave type, in one leave year.
 *
 * The three `*_days` columns are the *credits*. What has been taken is not here
 * and is deliberately not stored: the balance is
 * `opening + carried_in + accrued + adjustments − taken`, computed from
 * leave_days on every read. A stored balance column drifts against the days
 * actually consumed and nothing reports the drift — the same rule the chart of
 * accounts follows for account balances.
 *
 * `leave_year_start` / `leave_year_end` are on the row, and that is what makes
 * `leave.year_basis` safe to change. Were the window derived at read time,
 * switching the basis from calendar to fiscal in June would move every
 * entitlement, restate every balance mid-year, and land approved leave in a year
 * that no longer exists. Stored, a setting change governs entitlements created
 * *after* it and the current year keeps the basis it began with — which is
 * docs/hrms-plan.md §4.7's one rule: a setting decides what happens next, never
 * what already happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_entitlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // Restrict rather than cascade: a type somebody has taken leave
            // against is not a row to delete quietly, and losing the entitlement
            // would leave leave_days pointing at an entitlement that never
            // existed. Deactivate the type instead — is_active is what that is for.
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();

            $table->date('leave_year_start')
                ->comment('Stamped from leave.year_basis at creation — never derived at read time');
            $table->date('leave_year_end');

            // Days brought in by a migration or a correction at set-up: what this
            // employee already had when the module started running. Distinct from
            // carried_in_days, which the year-end reset computes.
            $table->decimal('opening_days', 5, 1)->default(0)
                ->comment('What they already had when this module started — set-up only');

            $table->decimal('accrued_days', 5, 1)->default(0)
                ->comment('Earned under the type\'s accrual_method, pro-rated in a first year if the setting says so');

            // Written by the year-end reset as min(unused, max_carry_forward) with
            // leave.carry_forward on, and 0 with it off. The column exists because
            // a setting a company can switch on is something that writes to it —
            // which is what distinguishes it from the `viewed` column the
            // invoice_events migration refused.
            $table->decimal('carried_in_days', 5, 1)->default(0)
                ->comment('From the previous year, capped by leave_types.max_carry_forward');

            $table->timestamps();

            // One entitlement per employee per type per year. Without this, a
            // second reset run — or two admins generating the year at once —
            // silently doubles somebody's allowance, and the balance formula sums
            // both rows rather than reporting the duplicate.
            $table->unique(['employee_id', 'leave_type_id', 'leave_year_start'], 'leave_entitlements_unique_year');

            // The lookup every balance read makes: this employee, this type, the
            // year covering a given date.
            $table->index(['employee_id', 'leave_year_start', 'leave_year_end'], 'leave_entitlements_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlements');
    }
};
