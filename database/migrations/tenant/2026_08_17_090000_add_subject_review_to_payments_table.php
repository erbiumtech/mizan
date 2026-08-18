<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A salary payment carries the review state of the payslip behind it.
 *
 * `Payment::isReleasable()` refuses to send a salary until the employee has accepted their payslip, and
 * it answered that by reading `payslip->employee_review` — the last thing making Accounting depend on
 * Payroll for a *rule* rather than for a figure. See docs/module-packaging-plan.md §8 Group C.
 *
 * **Three columns rather than the one the plan named.** §8 proposed a single `subject_accepted_at`
 * timestamp, and that cannot carry what the screen already says: a payment blocked because the employee
 * *rejected* the payslip shows the rejection reason and is categorised `BLOCK_REJECTED`, while one merely
 * awaiting acceptance is `BLOCK_UNACCEPTED`. A timestamp collapses those two into "not accepted", losing
 * the reason and the distinction the bank-file screen colours rows by. So the state, the reason and the
 * time all move.
 *
 * Nothing is dropped. `payslip_id` stays as a data-only foreign key — it is what the backfill reads and
 * what a person needs to find the payslip a payment came from — and `payslips.employee_review` remains
 * the source of truth. These columns are a copy kept current by a listener, which is the trade this makes:
 * a denormalisation that can go stale, in exchange for a module boundary that can be packaged. The
 * listener is the thing to review, not this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('subject_review')->nullable()
                ->comment('Copy of payslips.employee_review: pending, accepted or rejected');
            $table->text('subject_review_reason')->nullable()
                ->comment('Copy of payslips.employee_rejection_reason, shown when a release is blocked');
            $table->timestamp('subject_reviewed_at')->nullable();
        });

        $this->backfill();
    }

    /**
     * Every salary payment gets the state its payslip is in right now.
     *
     * One statement rather than a row-by-row walk: a company with three years of monthly payroll has
     * thousands of these, and a migration that loops is a migration somebody kills half-way. `payslip_id`
     * is unique on payments, so there is no fan-out to worry about.
     *
     * **Correlated subqueries, not `UPDATE ... JOIN`.** The join form is MySQL-only and this suite runs on
     * SQLite (`phpunit.xml`), so the join version passed nothing and would have failed on the first test
     * to provision a tenant. Subqueries in `SET` are understood by both.
     *
     * Payments with no payslip — a supplier, a rent transfer — are left null, which is exactly what
     * `isReleasable()` treats as "nothing to wait for".
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        DB::statement('
            UPDATE payments
            SET subject_review = (SELECT employee_review FROM payslips WHERE payslips.id = payments.payslip_id),
                subject_review_reason = (SELECT employee_rejection_reason FROM payslips WHERE payslips.id = payments.payslip_id),
                subject_reviewed_at = (SELECT employee_reviewed_at FROM payslips WHERE payslips.id = payments.payslip_id)
            WHERE payslip_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['subject_review', 'subject_review_reason', 'subject_reviewed_at']);
        });
    }
};
