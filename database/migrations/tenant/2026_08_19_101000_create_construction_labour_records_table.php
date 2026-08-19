<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A day's work by one person on one cost code — `docs/construction-management-plan.md` §7.1 and §7.3.
 *
 * **Minutes, not hours**, like `timesheet_entries` and attendance before it, because "a rate multiplied by a rounded
 * decimal of hours accumulates visible error across a month". Seven and a half hours is 450 minutes and stays 450.
 *
 * **`cost_rate_per_hour` and `burden_percent` are snapshotted at approval**, which is the second line of defence §7.2
 * asks for: the dated rate table stops a wage revision restating history, and this snapshot means even an edit to the
 * wrong rate row cannot reach cost that has already been booked. `labour_rate_id` records *which* row the figures came
 * from, because a snapshot nobody can trace back is a number somebody will eventually have to defend without evidence.
 *
 * **Two cost entries, not one** (§7.3). The labour entry and the burden entry point at the same job, WBS node and cost
 * code, and the burden one carries `is_burden` — so "labour cost" stays one number and burden stays separable in the
 * same breath. `burden_entry_id` beside `cost_entry_id` is what makes reversing a record reverse both halves.
 *
 * Draft until approved, and the approval is what books cost. A site sheet is typed by whoever collected it and read by
 * somebody else before it becomes money, which is the same shape every other document in this suite keeps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_labour_records', function (Blueprint $table) {
            $table->id();

            /*
             * Restricted rather than cascading, deliberately: a worker with cost against their name may not be
             * removed, and the policy already refuses a delete. If a row is ever forced out at the database level,
             * this makes it fail loudly instead of silently taking a month of labour cost with it.
             */
            $table->foreignId('worker_id')->constrained('construction_workers')->restrictOnDelete();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->constrained('construction_cost_codes')->restrictOnDelete();

            /*
             * The trade **worked as**, which is not always the trade on the worker's record: a mason labouring for a
             * day is paid and costed as a labourer, and the rate ladder has to be asked about the work rather than
             * about the person. Null falls back to the worker's own trade.
             */
            $table->foreignId('trade_id')->nullable()->constrained('construction_trades')->nullOnDelete();

            $table->date('worked_on');

            // Minutes, unsigned. Overtime separate because it is paid at a multiple and because "how much overtime
            // did this job run" is a question somebody asks every month.
            $table->unsignedInteger('normal_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);

            $table->enum('status', ['draft', 'approved', 'reversed'])->default('draft');

            /*
             * **The snapshot** — §7.1's "the important part". Null while the record is a draft, written at approval,
             * and never recomputed afterwards: that is what makes a closed month's labour cost survive a rate
             * revision, a corrected rate row, or a rate row somebody deletes.
             */
            $table->foreignId('labour_rate_id')->nullable()
                ->constrained('construction_labour_rates')->nullOnDelete();
            $table->decimal('cost_rate_per_hour', 14, 4)->nullable();
            $table->decimal('overtime_multiplier', 6, 4)->nullable();
            $table->decimal('burden_percent', 8, 4)->nullable();

            // What the snapshot produced. Stored because they are what the cost entries were written from, so a
            // record and its entries can be compared without recomputing either.
            $table->decimal('labour_amount', 15, 2)->nullable();
            $table->decimal('burden_amount', 15, 2)->nullable();

            // The two halves of §7.3. Separate columns rather than one morph, because reversing has to find both and
            // "which of these two entries is the burden" is not a question worth asking at runtime.
            $table->foreignId('cost_entry_id')->nullable()
                ->constrained('construction_cost_entries')->nullOnDelete();
            $table->foreignId('burden_entry_id')->nullable()
                ->constrained('construction_cost_entries')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();

            // A correction is a reversal (§3.3), so the record keeps its row and says why.
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->string('description')->nullable();
            $table->text('notes')->nullable();

            /*
             * Where the record came from. Null on a hand-typed site sheet, and set to a `timesheet_entries` row by
             * the import of §7.1 — "where Timesheets *is* licensed, its entries import into labour records rather
             * than being read in place, carried on the `source` morph". Written through `ModuleMap::alias()`,
             * because `enforceMorphMap()` does not cover a plain column (§18.2).
             */
            $table->nullableMorphs('source');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // The three queries this table actually serves: a job's labour for a period, one person's week, and the
            // approval queue.
            $table->index(['job_id', 'worked_on']);
            $table->index(['worker_id', 'worked_on']);
            $table->index(['status', 'worked_on']);
            $table->index('cost_code_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_labour_records');
    }
};
