<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Material out of a site store — `docs/construction-management-plan.md` §6.
 *
 * **"The issue document is construction's regardless."** §6 draws the line and gives the reason: an issue carries job,
 * WBS node, cost code, product, quantity, FIFO unit cost, returned quantity, wastage and its reason, and **two names —
 * who issued and who received, because the paper docket has two signatures**. None of that belongs on
 * `stock_movements`, which is deliberately thin and already carries `nullableMorphs('source')` for exactly this
 * division: the module owns the document, Inventory owns the movement.
 *
 * **An issue does not add cost, and that is the load-bearing rule of this table.** §6 makes materials on site
 * "delivered, costed, not yet consumed", so the *receipt* is what costs the material — a store receipt writes its
 * accrual against the code it was received at. An issue therefore **reclassifies**: it takes the FIFO value out of the
 * code the material was received at and puts it on the code it was used on, as a pair of `reclass` entries that sum to
 * zero. Adding cost here instead would charge every stocked delivery twice, and both figures would look like material
 * cost on the same job.
 *
 * That is what `unit_cost` on the line is for, and why `InventoryValuationService::consume()` had to start reporting
 * *which lots* it took: a lot names the goods-receipt line it arrived on, and that line names the cost code.
 *
 * **Wastage is its own stock movement**, of the `waste` type the same §6 added to the enum. An issue of twelve tonnes
 * of which one is wasted is eleven `issue` and one `waste`, so "what did we waste" is a query on a type rather than a
 * column somebody has to remember to subtract. Both are cost the company paid for and both stay on the job.
 *
 * **A return is recorded against the line it came from**, which is what §6's `returned_quantity` on the line means: the
 * material goes back into the store as a `return` movement at the cost it left at, and the reclass is unwound
 * pro-rata. A separate return document would be a second numbering series for the reversal of a docket that is still
 * sitting in the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_material_issues', function (Blueprint $table) {
            $table->id();

            // `MI-2026-0001`, per year rather than per job: a docket comes out of a store, and one store serves a
            // development's sub-jobs. The same reasoning `construction_commitments.number` records.
            $table->string('number');

            /*
             * The store the material came out of. Required — an issue with no store is a cost reallocation, not an
             * issue, and there is a cost-entry screen for that.
             */
            $table->foreignId('stock_location_id')->constrained('stock_locations')->restrictOnDelete();

            $table->date('issued_on');

            /*
             * **The two signatures the paper docket carries** (§6).
             *
             * Both are nullable worker links with a free-text fallback beside them, because the storeman knows who
             * collected the material long before that person is on any register — and a docket that cannot be recorded
             * until somebody is registered is a docket that gets written on paper and lost.
             */
            $table->foreignId('issued_by_worker_id')->nullable()
                ->constrained('construction_workers')->nullOnDelete();
            $table->string('issued_by_name')->nullable();
            $table->foreignId('received_by_worker_id')->nullable()
                ->constrained('construction_workers')->nullOnDelete();
            $table->string('received_by_name')->nullable();

            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();

            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->string('reference')->nullable()->comment('The docket number on the paper the storeman signed');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('number');
            $table->index(['stock_location_id', 'issued_on']);
            $table->index(['status', 'issued_on']);
        });

        Schema::create('construction_material_issue_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('material_issue_id')->constrained('construction_material_issues')->cascadeOnDelete();

            // The job on the line, not the header, for the reason every other line in this suite gives: one docket out
            // of a development's store serves several of its jobs, and one docket per job is a docket nobody writes.
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->constrained('construction_cost_codes')->restrictOnDelete();

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // What left the store in total, and how much of it was wasted rather than built in. The usable part is the
            // difference, and both halves are consumed from stock because both physically left.
            $table->decimal('quantity', 14, 4);
            $table->decimal('wastage_quantity', 14, 4)->default(0);
            $table->string('wastage_reason')->nullable();

            // The FIFO value of the whole quantity, and the blended unit cost it implies. Written at posting and never
            // recomputed — the lots it came from will have been consumed by then, so recomputing is impossible as well
            // as wrong.
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('amount', 15, 2)->nullable();

            /*
             * How much has come back. Incremented by a return against this line, never above `quantity` — more material
             * back than went out is either the wrong line or a receipt somebody has recorded as a return.
             */
            $table->decimal('returned_quantity', 14, 4)->default(0);

            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['material_issue_id']);
            $table->index(['job_id', 'cost_code_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_material_issue_lines');
        Schema::dropIfExists('construction_material_issues');
    }
};
