<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods receipts — `docs/construction-management-plan.md` §5, and the point where cost first touches the job.
 *
 * §5: "A goods receipt does three things: relieves the commitment, raises an accrual cost entry at order rate, and —
 * only when the delivery is into a site store rather than straight to the work face, and only when Inventory is
 * licensed — writes a stock movement (§6)."
 *
 * **Two of the three are built here. The third is not, and its absence is stated rather than silent.** The store path
 * needs `stock_locations`, which §6 assigns to Inventory and Phase 8 delivers; until then a receipt marked for a
 * store is **refused with a message naming what is missing**, rather than accepted and quietly costed as if it had
 * been stocked. §18.1 names this class of thing: a healthy-looking zero has to explain itself.
 *
 * **Direct to site is the default and touches no stock at all** (§6), which is what keeps this usable by a contractor
 * who buys everything to site and tracks no stock — "which is most of them, most of the time".
 *
 * The accrual matters more than it looks. Between delivery and invoice the job has incurred cost that no supplier
 * document yet proves, and a job cost report that waited for the invoice would understate every month end. Booking it
 * at **order rate** is the honest estimate available on the day: the invoice may disagree, and §5's three-way match is
 * where that disagreement is somebody's decision rather than a silent adjustment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number')->comment('GRN-2026-0142');

            /*
             * The order this delivery is against. **Nullable**, because a delivery that arrives with no order is a
             * real event — and refusing to record it would leave the material on site, uncosted, with the only
             * remedy being to invent an order after the fact.
             */
            $table->foreignId('commitment_id')->nullable()->constrained('construction_commitments')->nullOnDelete();

            // Guarded on Invoicing, which owns Contacts. Without it the supplier is whatever the delivery note says.
            $table->unsignedBigInteger('contact_id')->nullable();

            $table->date('received_on');
            // The supplier's own paperwork, which is what a three-way match is argued from.
            $table->string('delivery_note_reference')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            // Where on site it went (§16.5's location tree), for a company that tracks that.
            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();

            /*
             * `draft` while somebody is typing it, `posted` once it has relieved the order and raised its accruals,
             * `reversed` when it turns out the delivery did not happen. **Not deleted** — a posted receipt has moved
             * the committed figure and the cost report, and both need the reversal to be a row somebody can read.
             */
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('number');
            $table->index(['commitment_id', 'status']);
            $table->index('received_on');
        });

        Schema::create('construction_goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('construction_goods_receipts')->cascadeOnDelete();

            // The order line this satisfies. Nullable for the same reason the header's is.
            $table->foreignId('commitment_line_id')->nullable()
                ->constrained('construction_commitment_lines')->nullOnDelete();

            // Carried on the line rather than read through the order, because a receipt with no order still has to
            // say which job and which code the cost lands on.
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->constrained('construction_cost_codes')->restrictOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();

            $table->text('description');
            $table->decimal('quantity', 14, 4);
            $table->string('unit_of_measure', 16)->nullable();
            // The order rate, snapshotted: the invoice may disagree, and the three-way match is where that is a
            // decision rather than an adjustment nobody sees.
            $table->decimal('unit_rate', 14, 4)->nullable();
            $table->decimal('amount', 15, 2)->default(0);

            /*
             * §6's fork. `direct` is the default and touches no stock; `store` needs `stock_locations` and is
             * refused until Phase 8 delivers it — see the class docblock on why refusing beats accepting.
             */
            $table->enum('destination', ['direct', 'store'])->default('direct');

            /*
             * The accrual this line raised. Kept so posting is **idempotent and traceable both ways**: a second post
             * finds the entry already there and does nothing, and a reader of the cost entry can get back to the
             * delivery note it came from.
             */
            $table->unsignedBigInteger('cost_entry_id')->nullable();

            $table->timestamps();

            $table->index(['job_id', 'cost_code_id']);
            $table->index('commitment_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_goods_receipt_lines');
        Schema::dropIfExists('construction_goods_receipts');
    }
};
