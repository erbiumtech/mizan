<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attributing a supplier invoice to jobs and cost codes — `docs/construction-management-plan.md` §5.
 *
 * **A table, not three columns on `invoice_lines`, and §5 gives two reasons.**
 *
 * One line — *"rebar, 12 t"* — is routinely split across two jobs and three cost codes. Columns force a 1:1, and the
 * workaround is splitting the invoice line, "which makes the document this application prints disagree with the one the
 * supplier sent, discovered months later during a dispute".
 *
 * And it keeps construction's schema inside construction's tables. Three construction-shaped columns on
 * `invoice_lines` would make Invoicing carry this module's schema and would point a `ModuleBoundaryTest` coupling the
 * wrong way — Invoicing knowing about jobs, rather than construction knowing about invoices.
 *
 * **The cost of the choice, stated as plainly as §5 states it: a purchase invoice can be posted with no allocation at
 * all, and the general ledger is perfectly correct while the job is under-costed.** It is the single most likely silent
 * failure in the module. Two things answer it, both structural rather than procedural — the *Invoices awaiting
 * allocation* queue that ships with this migration, and §4.2's always-rendered reconciliation section, which shows zero
 * rather than being hidden.
 *
 * **The receipt's accrual is not touched here.** §4.5 is explicit: accruals auto-reverse at the opening of the next
 * period rather than being matched off against the eventual invoice, "because matching an accrual line-by-line to a
 * later invoice is the same heuristic that fails for commitment relief, and an accrual that fails to match sits on the
 * balance sheet forever with nobody able to say what it is for".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_invoice_allocations', function (Blueprint $table) {
            $table->id();

            /*
             * The supplier invoice. A real foreign key, because `invoices` exists in every tenant database whatever
             * the company has licensed — the same treatment `construction_jobs.client_contact_id` gets for `contacts`.
             * What Invoicing's licence gates is the *screen*, not the schema.
             */
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            /*
             * The line, where the allocation is against one. **Nullable**, because an invoice with a single narrative
             * line — most subcontract and haulage bills — is allocated at header level, and forcing a line reference
             * would make somebody invent lines that the supplier's document does not have.
             */
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->nullOnDelete();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->constrained('construction_cost_codes')->restrictOnDelete();

            /*
             * The order this invoice is against, where there is one. It is what lets the invoice relieve the
             * **unreceived** balance — §5's double-relief rule — and what gives §5's three-way match its third leg:
             * ordered against received against invoiced, per commitment line.
             */
            $table->foreignId('commitment_line_id')->nullable()
                ->constrained('construction_commitment_lines')->nullOnDelete();

            // **Signed**, like every other amount in this suite: a credit note allocates negative, and the job cost
            // falls by it. One convention, stated once.
            $table->decimal('amount', 15, 2);
            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('description')->nullable()->comment('Overrides the invoice line description on the cost entry');

            /*
             * The actual cost entry this allocation raised. Kept so allocating is **idempotent and traceable both
             * ways** — the same reason a goods receipt line keeps its accrual.
             */
            $table->unsignedBigInteger('cost_entry_id')->nullable();

            $table->unsignedBigInteger('allocated_by')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'invoice_line_id']);
            $table->index(['job_id', 'cost_code_id']);
            $table->index('commitment_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_invoice_allocations');
    }
};
