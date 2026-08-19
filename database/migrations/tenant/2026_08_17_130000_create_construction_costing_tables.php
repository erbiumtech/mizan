<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The job-cost ledger — `docs/construction-management-plan.md` §3, the decision the plan turns on.
 *
 * **Construction keeps its own cost ledger and reconciles to the books**, rather than adding a job dimension to
 * `journal_entry_lines` the way the retail plan chose for stores. §3.1 gives three reasons that are not
 * preferences: a cost entry carries `quantity`, `unit_of_measure` and `unit_rate` — the unit rate is the whole
 * of cost control and is unavailable from money alone, and putting those on every payroll and bank posting
 * would impose construction's shape on the entire application; **not every job cost is a general-ledger
 * event** (burden absorbed at a rate, internal plant, a notional tender comparison — some post as recoveries,
 * some deliberately never post, and a ledger that must balance cannot hold the ones that never post); and cost
 * periods and fiscal periods are **different clocks**.
 *
 * **The price is that nothing forces the two to agree, and §4 is what pays it.** If §4 is ever descoped, this
 * section should be descoped with it — the pair only makes sense together.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * §3.4. `period_start` is unique and is the first day of the cost month — a **date, not a 1–12 index**,
         * because a job runs three years and a month index cannot say which year's March it means.
         *
         * The control totals live here rather than in a report so that closing a period *records* what the two
         * ledgers said at the time. A reconciliation computed later from live data cannot tell you what the
         * figures were on the day somebody signed the certificate.
         */
        Schema::create('construction_cost_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_start')->unique();
            $table->date('period_end');
            $table->enum('status', ['open', 'closed', 'reconciled'])->default('open');

            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('reconciled_at')->nullable();

            // What each ledger said when the period closed, and the gap between them. §4's report is built on
            // these; a difference is a fact to explain rather than an error to hide.
            $table->decimal('gl_control_total', 15, 2)->nullable();
            $table->decimal('jc_control_total', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        /*
         * §3.2's closing paragraph: a batch gives a bulk operation **one thing to reverse**.
         *
         * "A reversal that has to re-find its two hundred rows by predicate is a reversal that will one day
         * find a hundred and ninety-nine, and nothing will say which one it missed."
         */
        Schema::create('construction_cost_batches', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['manual', 'labour', 'allocation', 'accrual', 'accrual_reversal', 'import', 'reversal'])
                ->default('manual');
            $table->date('period_start');
            $table->string('description')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            // The batch this one reverses, so "has this allocation been backed out" is one column rather than
            // a scan of two hundred rows.
            $table->foreignId('reversed_batch_id')->nullable()->constrained('construction_cost_batches')->nullOnDelete();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['kind', 'period_start']);
        });

        Schema::create('construction_cost_entries', function (Blueprint $table) {
            $table->id();

            // Always a cost-bearing job. Cost attaches to any job in the tree (§1.2) and rolls up through
            // `path`, so a sub-job's cost is its own and its parent's report shows both.
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->constrained('construction_cost_codes')->restrictOnDelete();

            // **Snapshotted, not read through the code.** Re-typing a cost code in June must not restate
            // March's labour/material split — the same reasoning that stores `invoice_lines.tax_amount`
            // rather than recomputing it.
            $table->enum('cost_type', ['labour', 'material', 'plant', 'subcontract', 'other']);

            $table->enum('kind', ['actual', 'accrual', 'allocation', 'reclass', 'reversal'])->default('actual');

            // **One signed amount, not a debit and a credit.** A debit/credit pair here imports an accounting
            // form that buys nothing and invites "which column does a credit note go in", which two developers
            // will answer differently.
            $table->decimal('amount', 15, 2);

            // The unit rate is the whole of construction cost control and is unavailable from money alone —
            // which is §3.1's first reason for this ledger existing at all.
            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('unit_of_measure', 16)->nullable();
            $table->decimal('unit_rate', 14, 4)->nullable();

            $table->date('incurred_on');
            // The first day of the cost month. A date rather than an index, same as the period.
            $table->date('posting_period');
            // A late supplier invoice dated into a closed month lands in the **open** period with `incurred_on`
            // preserved and this set — §3.4. Reopening a signed-off period to slot one invoice in invalidates
            // the WIP snapshot, the client certificate and the GL summary that all depended on that total.
            $table->boolean('is_late_for_period')->default(false);

            $table->unsignedBigInteger('fiscal_year_id')->nullable();

            /*
             * **A column, never inferred from `journal_entry_id IS NULL`** — §3.2.
             *
             *   mirrored — the GL posting is the source and this mirrors it
             *   posted   — this is the source and it has reached the GL
             *   pending  — it should reach the GL and has not yet
             *   memo     — it deliberately never will (a notional tender comparison, an unposted overhead
             *              allocation). **Not burden and not internal plant**, which this comment named until
             *              Phase 7b: §7.3 requires both to credit a recovery account, so both are `pending`
             *              until §11 posts them. See CostEntry::GL_MEMO.
             *
             * `pending` and `memo` look identical as a null, and a null that means "we do not know which" is
             * exactly how a sub-ledger drifts for a year unnoticed.
             */
            $table->enum('gl_treatment', ['mirrored', 'posted', 'pending', 'memo'])->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->timestamp('posted_to_gl_at')->nullable();

            $table->foreignId('batch_id')->nullable()->constrained('construction_cost_batches')->nullOnDelete();

            // Who or what the cost is about. All nullable and all guarded: `employees` and the worker register
            // are other modules' (§18.1), so a company without them still records cost.
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('worker_id')->nullable();

            // Burden charged at a rate, which §4.3 requires to be absorbed or both ledgers diverge.
            $table->boolean('is_burden')->default(false);

            // A correction is a reversal pointing back, never an edit (§3.3), once the period is closed or the
            // entry has reached the GL.
            $table->foreignId('reverses_id')->nullable()->constrained('construction_cost_entries')->nullOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('construction_cost_entries')->nullOnDelete();

            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            // The document that caused it — a goods receipt, a certificate, a daily log. Written through
            // ModuleMap::alias(), because enforceMorphMap() does not cover a plain column (§18.2).
            $table->nullableMorphs('source');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            /*
             * The indexes the cost report actually uses. It groups by cost code within a job and a period, and
             * §18.2 asks for a query budget on that page before it exists — so the shape it will run is
             * indexed now rather than discovered later.
             *
             * Deliberately **no** `is_active` or `deleted_at`: §3.2's invariant is that the sum of `amount`
             * filtered by nothing but the period *is* the cost. "A flag that must be filtered is a flag
             * somebody forgets, and the query that forgets it is a cost report that is wrong and looks fine."
             */
            $table->index(['job_id', 'posting_period']);
            $table->index(['job_id', 'cost_code_id', 'posting_period']);
            $table->index(['posting_period', 'gl_treatment']);
            $table->index('wbs_node_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_cost_entries');
        Schema::dropIfExists('construction_cost_batches');
        Schema::dropIfExists('construction_cost_periods');
    }
};
