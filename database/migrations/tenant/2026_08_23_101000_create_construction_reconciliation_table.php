<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every reconciliation run, kept — `docs/construction-management-plan.md` §4.3.
 *
 * **"Five mechanisms, because a report nobody opens is not a control."** This table is what the other four hang off: a
 * scheduled command writes a row and notifies when unbalanced; the period cannot be closed while unbalanced; unless
 * somebody holding `ConstructionPeriodForceClose` accepts the difference **with a stated reason**, which is recorded
 * here; and a forced close never fudges the ledger, so the difference stays visible in every later period until the
 * cause is fixed.
 *
 * **The totals are stored rather than recomputed**, which is §3.4's argument about the period's control totals applied
 * one level up: "a reconciliation computed later from live data cannot tell you what the figures were on the day
 * somebody signed the certificate." A run is a dated statement about two ledgers. Recomputing March's difference with
 * today's data would silently restate the thing somebody accepted, and the reason they gave would no longer be attached
 * to any figure.
 *
 * **`causes` is JSON, and that is a deliberate exception to this suite's usual preference for columns.** The cause
 * breakdown is a report, not a query surface: nothing filters on "how many entries were pending by age in March", and a
 * table per cause would be seven tables whose only reader is one page. What *is* queried — the totals, the difference,
 * the status — is columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_reconciliations', function (Blueprint $table) {
            $table->id();

            // The cost month, as a date. §3.4's rule: a month index cannot say which of three years' Marches it means.
            $table->date('period_start');

            $table->timestamp('run_at');
            $table->unsignedBigInteger('run_by')->nullable()->comment('Null for the scheduled run');

            /*
             * §4.2's arithmetic, one column per line of it, so the stored row *is* the printed statement.
             *
             *   GL cost for the period
             *     less GL cost carrying no job          [UNALLOCATED — shown, never spread]
             *     plus job cost still awaiting the GL   [the reconciling item]
             *   = expected job-cost total
             *     vs Σ cost entries where gl_treatment != 'memo'
             *   difference                              must be 0.00
             */
            $table->decimal('gl_cost', 15, 2)->default(0);
            $table->decimal('unallocated_gl_cost', 15, 2)->default(0);
            $table->decimal('pending_job_cost', 15, 2)->default(0);
            $table->decimal('expected_job_cost', 15, 2)->default(0);
            $table->decimal('job_cost', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->default(0);

            $table->enum('status', ['balanced', 'unbalanced', 'accepted'])->default('unbalanced');

            /*
             * The drill-down by cause, which §4.2 says "is what makes it a tool rather than a number".
             *
             * Stored as it was computed, because a cause list recomputed later would explain today's difference and not
             * the one somebody accepted.
             */
            $table->json('causes')->nullable();

            /*
             * Accepting a difference. **The reason is the control**, and §4.3 is explicit that a forced close "never
             * fudges the ledger. No plug entry, no balancing figure." Both ledgers stay true and the difference stays
             * visible in every later period until the cause is fixed — so what this records is not a fix, it is a name
             * against a decision to carry on.
             */
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_by')->nullable();
            $table->text('accepted_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // The two reads: a period's history, and "what is unbalanced right now".
            $table->index(['period_start', 'run_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_reconciliations');
    }
};
