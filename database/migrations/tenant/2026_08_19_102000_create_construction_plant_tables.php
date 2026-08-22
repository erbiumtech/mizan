<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fleet and what it did — `docs/construction-management-plan.md` §7.3.
 *
 * **"Plant is the same shape and the same trap"** as burden. Charging a job internal hire for a company-owned
 * excavator must credit a Plant Internal Hire Recovery account, against which depreciation, fuel, repairs and the
 * operator accumulate: "debit the job with no credit and the fleet looks free while every job looks expensive".
 *
 * **Owned and hired plant behave differently, and the difference is which document reaches the general ledger.**
 * An owned machine has no invoice, so the log *is* the cost — internal hire, posted by §11 against the recovery
 * account. A hired machine has a supplier invoice, which is already the GL's record of the cost (§4.1) and reaches
 * the job through §5's allocation chain — so its log writes **no cost at all** and becomes the *check* instead:
 * "days on site times rate against the invoice, which is a two-way match that catches the classic over-billing of
 * plant left standing after it was collected". `commitment_id` is what makes that match possible.
 *
 * **Three unit columns, because plant is charged three ways.** Working, idle and standby are different rates in
 * every hire agreement in the industry, and a single "hours" column would force whoever enters the log to do the
 * blending in their head and lose the breakdown that answers "how much did we pay for a crane to stand still".
 *
 * **Rates live on the item and are snapshotted onto the log**, which is deliberately *not* the dated table §7.2
 * builds for labour. The asymmetry is stated in `PlantItem` and it is a real limitation rather than an oversight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_plant_items', function (Blueprint $table) {
            $table->id();

            $table->string('code', 32);
            $table->string('name');
            $table->string('category')->nullable()->comment('Excavator, tower crane, dumper — the company\'s own words');
            $table->string('registration', 64)->nullable();

            /*
             * **Which of these a machine is decides whether its log books cost** (§7.3, §4.1).
             *
             *   owned                — no invoice exists, so the log is the cost: internal hire, credited to recovery
             *   hired                — a supplier invoice is the GL's record; the log is the check against it
             *   hired_with_operator  — the same as hired, and the distinction is kept because the operator is the
             *                          supplier's rather than this company's, so no labour record accompanies it
             */
            $table->enum('ownership', ['owned', 'hired', 'hired_with_operator'])->default('owned');

            /*
             * The asset behind an owned machine. `accounting` is a declared requirement of this module, so the table
             * is always there — and the link is what lets §11 accumulate depreciation against the recovery account
             * this machine's charges credit. `DepreciationService` already exists.
             */
            $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets')->nullOnDelete();

            // Who it is hired from. A supplier is a Contact, which Invoicing owns, so the column stays null without
            // that module — the same guarded shape as a purchase order's supplier.
            $table->foreignId('supplier_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            /*
             * The hire order, and the reason the two-way match of §7.3 is possible at all: the invoices for a hired
             * machine are allocated against this commitment's lines, so cumulative invoiced can be compared with
             * cumulative logged. Null on owned plant, which has no order.
             */
            $table->foreignId('commitment_id')->nullable()->constrained('construction_commitments')->nullOnDelete();

            $table->enum('meter_unit', ['hours', 'kilometres'])->default('hours');

            $table->foreignId('default_cost_code_id')->nullable()
                ->constrained('construction_cost_codes')->nullOnDelete();

            /*
             * **Three rates, all nullable, and a null means "not charged".**
             *
             * Stated rather than assumed, because the alternative — idle falling back to the working rate — would
             * silently inflate every job that ever had a machine standing, and §18.1's rule is that a figure which
             * looks healthy while hiding something has to say so. The log prints "idle 4.0, not charged" so the
             * choice is visible on the row rather than buried in a rate table.
             */
            $table->decimal('working_rate', 14, 4)->nullable();
            $table->decimal('idle_rate', 14, 4)->nullable();
            $table->decimal('standby_rate', 14, 4)->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('code');
            $table->index(['ownership', 'is_active']);
            $table->index('commitment_id');
        });

        Schema::create('construction_plant_logs', function (Blueprint $table) {
            $table->id();

            // Restricted like the worker on a labour record: a machine with cost against it may not be removed.
            $table->foreignId('plant_item_id')->constrained('construction_plant_items')->restrictOnDelete();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->constrained('construction_cost_codes')->restrictOnDelete();

            $table->date('logged_on');

            // The three ways a machine's day is charged. Decimal rather than minutes, unlike labour: plant is hired
            // and charged in hours or days to two places by every agreement in the industry, and the unit is the
            // agreement's rather than ours.
            $table->decimal('working_units', 10, 2)->default(0);
            $table->decimal('idle_units', 10, 2)->default(0);
            $table->decimal('standby_units', 10, 2)->default(0);

            /*
             * The meter, which is evidence rather than the basis of the charge.
             *
             * A machine's engine hours legitimately differ from its charged hours — warming up, travelling, an
             * operator leaving it running — so a mismatch is not refused. A meter reading that went *backwards* is,
             * because a meter cannot, and that is either a typo or a replaced instrument somebody must explain.
             */
            $table->decimal('meter_start', 14, 2)->nullable();
            $table->decimal('meter_end', 14, 2)->nullable();

            $table->decimal('fuel_quantity', 12, 3)->nullable()->comment('Issued to this machine on this day');

            // The operator, where this company supplies one. Null on hired-with-operator plant, whose operator is
            // the supplier's and whose time this company never costs.
            $table->foreignId('operator_worker_id')->nullable()
                ->constrained('construction_workers')->nullOnDelete();

            $table->string('downtime_reason')->nullable();

            $table->enum('status', ['draft', 'approved', 'reversed'])->default('draft');

            // The snapshot, taken at approval, for the reason §7.1 takes one on a labour record: the charge has to
            // survive somebody editing the machine's rate next month.
            $table->decimal('working_rate', 14, 4)->nullable();
            $table->decimal('idle_rate', 14, 4)->nullable();
            $table->decimal('standby_rate', 14, 4)->nullable();
            $table->decimal('charge_amount', 15, 2)->nullable();

            /*
             * Null on **hired** plant even once approved, and that is the whole of §7.3's second half: the supplier
             * invoice is the GL's record of a hired machine's cost and reaches the job through §5's allocation, so a
             * cost entry here as well would charge the job twice for the same excavator.
             */
            $table->foreignId('cost_entry_id')->nullable()
                ->constrained('construction_cost_entries')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();

            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'logged_on']);
            $table->index(['plant_item_id', 'logged_on']);
            $table->index(['status', 'logged_on']);
            $table->index('cost_code_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_plant_logs');
        Schema::dropIfExists('construction_plant_items');
    }
};
