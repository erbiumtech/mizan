<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Punch and snag lists — `docs/construction-management-plan.md` §16.4.
 *
 * The list of things that are built and not right. Three columns on it carry money, and everything else on these tables
 * exists to make those three trustworthy.
 *
 * **`affects_practical_completion` is the flag §11's AIA holdback reads**, and §16.4 says so in as many words. Under an
 * AIA-style release the balance of retention falls due at Substantial Completion *less a punch-list holdback* — and
 * releasing the whole balance on a job with fifty open items is money that does not come back. Until this migration
 * existed, `RetentionService` could only take that holdback as zero and say in words that it did not know; now it can
 * read a figure.
 *
 * **`cost_to_rectify` and `back_charge_id` are what make a defect somebody else's.** A subcontractor's defect the main
 * contractor puts right is a cost with a recipient, and §12's back-charge register is where it goes. The link is one
 * integer here rather than a relation, because `construction_back_charges` belongs to `construction_costing` and this
 * module requires only `construction`.
 *
 * **`construction_punch_inspections` is a row per re-inspection attempt**, and §16.4 is explicit about why: "closed
 * after three failed re-inspections is a different fact from closed first time, and a single closed-at column loses it
 * — the same reasoning `tickets.reopened_count` already gives in its own migration: counted rather than inferred from
 * status history." A trade that never fixes anything first time is a back-charge case, and the attempt count is the
 * evidence for it.
 *
 * **The photographs are file columns, not register containers, and not diary photos either.** §16.1 already argued that
 * thousands of site photographs do not belong in the ISO 19650 register. A punch photograph is not a diary photograph
 * either: it belongs to the *item's* lifecycle rather than to a day, and its whole purpose is the before-and-after pair
 * — which is why the two live side by side on the row where the pairing is structural rather than remembered.
 *
 * `activity_id` is absent here as it is on RFIs and submittals: the programme sub-phase builds
 * `construction_activities` and adds the column to all three with a real foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_punch_lists', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id')->nullable();

            $table->string('name');

            /*
             * **The five kinds §16.4 names**, and the distinction that matters most is `client` against `internal`.
             *
             * An internal list is the contractor's own quality sweep, made before anybody else is invited to look; a
             * client list is the employer's. Merging them would put the contractor's own findings into a document the
             * employer can quote, which is the fastest way to teach a site team to stop writing anything down.
             */
            $table->enum('kind', ['pre_handover', 'handover', 'defects', 'client', 'internal'])
                ->default('pre_handover');

            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();

            $table->date('opened_on');
            $table->date('target_completion_date')->nullable();
            $table->date('closed_on')->nullable();

            $table->enum('status', ['open', 'closed'])->default('open');

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'status']);
            $table->index('kind');
        });

        Schema::create('construction_punch_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('punch_list_id')->constrained('construction_punch_lists')->cascadeOnDelete();
            // Denormalised from the list, and deliberately: every report on this table is per job, and reaching the job
            // through the list on a register of four thousand items is a join on every row of every screen.
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            // Per list, and gapless for the same reason §16.2's RFI numbering is: the items are read sequentially,
            // quoted in correspondence, and walked round site on a printout.
            $table->string('reference');

            $table->text('description');

            // The trade, unconstrained: `construction_trades` belongs to `construction_costing`, and a snag list must
            // be fillable on a job whose cost side is kept elsewhere. Same treatment as the diary's manpower line.
            $table->unsignedBigInteger('trade_id')->nullable();
            $table->string('trade_label')->nullable();

            /*
             * **Where, twice over.** The location tree answers "which room"; the grid reference answers "where in it".
             *
             * §16.5 built the tree so that five subsystems could stop keeping five spellings of "Level 3 East". The grid
             * reference is not a duplicate of it — it is how a structural defect is described on a drawing, and the
             * report that matters before handover needs both.
             */
            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();
            $table->string('grid_reference')->nullable();
            $table->foreignId('drawing_document_id')->nullable()
                ->constrained('construction_documents')->nullOnDelete();

            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');

            // Who is to put it right. A Contact, which Invoicing owns, so the label carries it without that module.
            $table->foreignId('responsible_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('responsible_label')->nullable();

            $table->date('raised_on');
            $table->unsignedBigInteger('raised_by')->nullable();
            $table->date('due_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->enum('status', ['open', 'in_progress', 'ready_for_inspection', 'closed', 'rejected'])
                ->default('open');

            /*
             * **The flag §11's AIA holdback reads** (§16.4).
             *
             * Default false, deliberately. Most snags are paint and sealant and do not stop anybody taking the building
             * over; a default of true would hold retention against every one of them and make the figure meaningless
             * within a week. It is a judgement somebody makes item by item, which is what gives the holdback its
             * standing.
             */
            $table->boolean('affects_practical_completion')->default(false);

            /*
             * What it costs to put right, and who is being charged for it.
             *
             * `cost_to_rectify` is an estimate at the time it is raised and stays one — it is what the holdback is
             * computed from, and §11 needs a figure before anybody has done the work. `back_charge_id` is unconstrained
             * because `construction_back_charges` is `construction_costing`'s.
             */
            $table->decimal('cost_to_rectify', 15, 2)->nullable();
            $table->unsignedBigInteger('back_charge_id')->nullable();

            /*
             * **The before-and-after pair**, as files on the row.
             *
             * Not register containers — §16.1 settled that thousands of site photographs bury the drawings the register
             * exists for — and not diary photographs either, because a punch photograph belongs to the item's lifecycle
             * rather than to a day. The pairing is the whole point of it, and two columns side by side make the pair
             * structural instead of something somebody has to remember to keep together.
             */
            $table->string('before_photo_path')->nullable();
            $table->string('after_photo_path')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['punch_list_id', 'reference']);
            $table->index(['job_id', 'status']);
            // The holdback query: open items on this job that stop the employer taking it over.
            $table->index(['job_id', 'affects_practical_completion', 'status']);
            $table->index(['location_id', 'status']);
            $table->index('trade_id');
            $table->index('back_charge_id');
        });

        /*
         * **A row per re-inspection attempt** — §16.4, and the fact a `closed_at` column cannot hold.
         *
         * "Closed after three failed re-inspections" is a different fact from "closed first time". The first is a trade
         * that does not fix things, three site visits nobody planned, and a back-charge argument with evidence behind
         * it; the second is a job going well. A status column records only the destination.
         */
        Schema::create('construction_punch_inspections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('punch_item_id')->constrained('construction_punch_items')->cascadeOnDelete();

            $table->unsignedInteger('attempt')->default(1);
            $table->date('inspected_on');
            $table->unsignedBigInteger('inspected_by')->nullable();
            $table->foreignId('inspector_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            /*
             * `partial` earns its place beside pass and fail.
             *
             * A snag that is half put right is the commonest outcome of a first re-inspection and the one that decides
             * whether a second visit is needed. Recording it as a failure loses the progress; recording it as a pass
             * closes an item that is not done.
             */
            $table->enum('result', ['passed', 'failed', 'partial']);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['punch_item_id', 'attempt']);
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_punch_inspections');
        Schema::dropIfExists('construction_punch_items');
        Schema::dropIfExists('construction_punch_lists');
    }
};
