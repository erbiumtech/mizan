<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commitments and their relief — `docs/construction-management-plan.md` §5.
 *
 * **Committed cost is the number a site manager actually manages by**: money promised to somebody and not yet
 * invoiced, which is what says a cost code is overspent *before* the invoice arrives. Nothing in this application
 * held it before this migration, which is why §5 calls it the largest net-new schema surface in the plan.
 *
 * Four decisions here, each rejecting a simpler shape that fails quietly.
 *
 * **One table for purchase orders, subcontracts and plant hire.** The four-column report and the relief mechanism
 * must behave identically for all three or "committed" means different things in one column. Two tables would make
 * every commitment query a `UNION`, "and a `UNION` is where one branch silently gains a filter the other does not
 * and the report still renders".
 *
 * **The job is on the line, not the header.** One order of rebar split across three sites is completely normal.
 * Forcing one order per job means either the supplier gets three orders for one delivery — which he will not
 * honour, and the delivery note then matches nothing — or somebody codes the whole load to one job. Both are silent
 * cost misallocation and the second is invisible.
 *
 * **Relief is an explicit row, not a subtraction.** The tempting alternative — *committed = ordered − invoiced*,
 * matched by job, code and supplier — is a heuristic that fails the first time one invoice covers two orders or one
 * line is part-delivered, and it fails by leaving an over-commitment nobody can point at and nobody can clear. With
 * explicit reliefs, open commitment is **provable** as `line.amount − Σ reliefs`, which is Phase 5's exit
 * condition.
 *
 * **Closing with a balance is an act with an author.** `closed_at`, `closed_by` and `close_reason` exist because a
 * purchase order that quietly stops changing is an open commitment nobody will ever clear — and the difference
 * between "the supplier delivered short and we agreed to leave it" and "somebody forgot" is a sentence in a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_commitments', function (Blueprint $table) {
            $table->id();
            $table->string('number')->comment('PO-2026-0142, SC-014-03');

            $table->enum('type', ['purchase_order', 'subcontract', 'plant_hire', 'manual'])
                ->default('purchase_order');

            // The supplier or subcontractor. Guarded on Invoicing, which owns Contacts — a commitment to somebody
            // this company has no contact record for is still a commitment, and §18.1's "smaller, never broken"
            // applies to procurement as much as to a job.
            $table->unsignedBigInteger('contact_id')->nullable();

            /*
             * The agreement this commits money under, where there is one — a subcontract is a
             * `construction_contracts` row with `side = payable`, and this is the money promised under it.
             *
             * Nullable and **unconstrained**: `construction_costing` does not require `construction_contracts`, so
             * a foreign key would make one module's schema depend on another's licence. The same treatment
             * `construction_jobs.project_id` gets, and the resolution recorded in §5's table.
             */
            $table->unsignedBigInteger('contract_id')->nullable();

            $table->enum('status', [
                'draft', 'pending_approval', 'approved', 'issued', 'partially_relieved', 'closed', 'cancelled',
            ])->default('draft');

            $table->string('currency_code', 3)->nullable();
            $table->decimal('exchange_rate', 20, 10)->nullable();

            $table->date('order_date')->nullable();
            $table->date('required_by')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            // Retention on a subcontract order, held from each certificate against it (§12).
            $table->decimal('retention_percent', 5, 2)->nullable();

            $table->text('description')->nullable();
            $table->string('supplier_reference')->nullable()->comment("The supplier's own quote or order number");

            // Each stamp is a different act by a different person, which is what makes the approval a control
            // rather than a status word.
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();

            /*
             * Closing an order with an open balance is intentional, never a number that quietly stops changing.
             * All three columns are written together by the service, and the reason is mandatory — a decision with
             * no record is not a control (§5's own words about accepted variances, and it applies here).
             */
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('close_reason')->nullable();

            $table->timestamps();

            $table->unique('number');
            $table->index(['type', 'status']);
            $table->index('contact_id');
            $table->index('contract_id');
        });

        Schema::create('construction_commitment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commitment_id')->constrained('construction_commitments')->cascadeOnDelete();

            /*
             * **The job is here rather than on the header** — see the class docblock. One order, three sites, and
             * the delivery note still matches the order the supplier was sent.
             */
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->constrained('construction_cost_codes')->restrictOnDelete();

            // Guarded on Inventory: a commitment line may name a stocked product, and without that module it is
            // a description and a rate, which is how most site orders are written anyway.
            $table->unsignedBigInteger('product_id')->nullable();

            $table->text('description');
            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('unit_of_measure', 16)->nullable();
            $table->decimal('rate', 14, 4)->nullable();
            $table->decimal('amount', 15, 2)->default(0);

            // Snapshotted off the code the same way a cost entry's is (§3.2): re-typing a cost code in June must
            // not restate what an order committed in March.
            $table->enum('cost_type', ['labour', 'material', 'plant', 'subcontract', 'other'])->default('material');

            $table->timestamps();

            $table->index(['job_id', 'cost_code_id']);
            $table->index('commitment_id');
        });

        /*
         * §5. Relief, one row per event, and the hazard the rule exists for:
         *
         * **Double relief.** The goods receipt relieves, and then the invoice for the same receipt must not relieve
         * again. The rule is that relief happens once, at the earlier of receipt or certificate, and the invoice
         * relieves only the **unreceived** balance — enforced in `CommitmentService`, because a receipt, a
         * certificate and an invoice allocation are three callers and a rule kept in one of them is a rule the
         * other two walk past.
         */
        Schema::create('construction_commitment_reliefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commitment_line_id')->constrained('construction_commitment_lines')->cascadeOnDelete();

            $table->enum('kind', ['receipt', 'certificate', 'invoice', 'cancellation', 'close_out']);

            /*
             * What relieved it — a goods receipt, a subcontract certificate, an invoice allocation. A
             * **plain-column morph**, so the type is written through `ModuleMap::alias()` in a mutator:
             * `enforceMorphMap()` does not cover plain columns, and §18.2 names this family of columns as the five
             * it misses.
             */
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            /*
             * **Signed like every other ledger in this suite**: positive relieves, negative gives commitment back.
             * A cancelled receipt is a negative relief rather than a deleted row, so "what did we think was
             * committed in March" stays answerable — the same discipline the cost ledger keeps with reversals.
             */
            $table->decimal('amount', 15, 2);
            $table->decimal('quantity', 14, 4)->nullable();

            $table->date('relieved_on');
            $table->string('reference')->nullable();
            $table->text('reason')->nullable()->comment('Mandatory on a cancellation or a close-out');
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['commitment_line_id', 'kind']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_commitment_reliefs');
        Schema::dropIfExists('construction_commitment_lines');
        Schema::dropIfExists('construction_commitments');
    }
};
