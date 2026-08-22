<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The programme — `docs/construction-management-plan.md` §13.
 *
 * **"Store the programme; never solve it."** That is the first line of §13 and it is the single most important thing
 * about these tables. There is no forward pass, no backward pass, no critical-path calculation, no float derivation,
 * no resource levelling and no Gantt editor — and there never will be, because building CPM is not the expensive part:
 * *keeping it in step with P6 is.*
 *
 * §13 spends its length on why. Every contractor large enough to be running FIDIC already owns P6 or Asta, and the
 * *contractual* programme lives there because that is what was submitted and accepted. **A second scheduler here that
 * disagreed with the submitted programme would be worse than no scheduler at all** — it manufactures a number the
 * quantity surveyor quotes in a claim and the planner does not recognise, and the disagreement stays invisible until an
 * adjudication.
 *
 * So `is_critical` and `total_float_days` are **imported columns, not derived ones**, and `source` sits beside them so
 * any report quoting a critical path can say where it came from. Predecessors are stored so an imported network
 * round-trips and a look-ahead can show what is blocking — **and no date in this application is ever calculated from
 * them.**
 *
 * What contract administration actually needs from a programme is four things, and all four are here:
 *
 *  - **A milestone with a contractual date**, so extension of time and liquidated damages have something to move.
 *  - **Planned against actual, with percent complete**, so a schedule index and a look-ahead exist.
 *  - **Somewhere to hang a delay event** — hence `construction_delay_events.activity_id` below.
 *  - **An activity identifier an RFI, a submittal and a variation can point at** — hence the two columns added to the
 *    registers Phase 9d and 9e built and deliberately left without one.
 *
 * `budgeted_value` is deliberately **not** reconciled to contract items. §13: "the programme and the bill are two
 * different decompositions of the same job, and forcing agreement between them produces a fiction that somebody then
 * has to maintain."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            /*
             * **The key an import is idempotent on.** P6's activity id, MS Project's UID.
             *
             * Nullable because a manually added activity has no external identity, and unique per job *and source* so
             * a job re-imported from two tools — the accepted programme from P6, a subcontractor's fragment from MS
             * Project — cannot collide on an id that means different things in each.
             */
            $table->string('external_id')->nullable();
            $table->enum('source', ['manual', 'p6_xer', 'p6_xml', 'msp_xml', 'asta'])->default('manual');

            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('construction_activities')->cascadeOnDelete();

            $table->string('code')->comment('The planner\'s own activity id, as printed on the programme');
            $table->string('name');

            $table->enum('activity_type', [
                'task', 'start_milestone', 'finish_milestone', 'level_of_effort', 'hammock', 'wbs_summary',
            ])->default('task');

            /*
             * **A milestone the contract names**, which is the one flag on this table that moves money.
             *
             * Extension of time moves it and liquidated damages are levied against it, so it is deliberately separate
             * from `activity_type`: a finish milestone in P6 is a scheduling device, and only some of them are
             * contractual dates. Conflating the two would apply damages to a planner's marker.
             */
            $table->boolean('is_contract_milestone')->default(false);

            /*
             * **`ld_applies` is what makes a missed milestone cost money**, and it is separate again from
             * `is_contract_milestone` because a contract names dates it does not price. Sectional completion of the
             * car park may be a contractual date with no damages against it at all.
             */
            $table->boolean('ld_applies')->default(false);

            /*
             * Two pairs of dates, and keeping them apart is the whole of an extension-of-time argument.
             *
             * **Baseline is the *accepted* programme** — what was submitted and agreed, and what entitlement is measured
             * against. **Planned is the current one**, which moves every month. A single pair would make every
             * re-programme silently retire the entitlement it was caused by, which is the failure §13 is written around.
             */
            $table->date('baseline_start')->nullable();
            $table->date('baseline_finish')->nullable();
            $table->date('planned_start')->nullable();
            $table->date('planned_finish')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_finish')->nullable();

            $table->unsignedInteger('original_duration_days')->nullable();
            $table->unsignedInteger('remaining_duration_days')->nullable();
            $table->decimal('percent_complete', 5, 2)->default(0);

            // Not reconciled to contract items, on purpose. See the class docblock.
            $table->decimal('budgeted_value', 15, 2)->nullable();

            /*
             * **Imported, never derived**, and this is the pair §13 names explicitly.
             *
             * Total float and criticality come out of the scheduling tool that produced the programme. Computing them
             * here would require the forward and backward pass §13 forbids, and — worse — would produce a second answer
             * to a question the accepted programme has already answered.
             */
            $table->integer('total_float_days')->nullable();
            $table->boolean('is_critical')->default(false);

            $table->foreignId('responsible_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // A milestone that gets paid. Unconstrained: `construction_contract_items` belongs to
            // `construction_contracts`, and this module requires only `construction`.
            $table->unsignedBigInteger('milestone_payment_item_id')->nullable();

            /*
             * Which baseline revision this row belongs to, and the date the progress was measured at.
             *
             * `data_date` is what makes percent complete meaningful: 40% as at the 1st and 40% as at the 30th are
             * different facts, and a programme without it cannot be compared with the month before.
             */
            $table->string('baseline_revision')->nullable();
            $table->date('data_date')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'source', 'external_id']);
            $table->index(['job_id', 'code']);
            // The look-ahead, and "what should have started by now".
            $table->index(['job_id', 'planned_start']);
            $table->index(['job_id', 'baseline_finish']);
            // The liquidated-damages exposure: contractual milestones, late.
            $table->index(['job_id', 'is_contract_milestone', 'actual_finish']);
            $table->index('is_critical');
        });

        /*
         * **Stored so the network round-trips; never solved.**
         *
         * §13: predecessors exist "so an imported network round-trips and a look-ahead can show what is blocking, and no
         * date is ever calculated from them". The relationship type and lag are kept because P6 exports them and a
         * round-trip that dropped them would corrupt the planner's file — not because anything here reads them to
         * compute a date.
         */
        Schema::create('construction_activity_predecessors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('activity_id')->constrained('construction_activities')->cascadeOnDelete();
            $table->foreignId('predecessor_activity_id')->constrained('construction_activities')->cascadeOnDelete();

            // Finish-to-start, start-to-start, finish-to-finish, start-to-finish — the four P6 exports.
            $table->enum('relationship', ['fs', 'ss', 'ff', 'sf'])->default('fs');
            // Signed: a negative lag is a lead, which is how overlapping trades are programmed.
            $table->integer('lag_days')->default(0);

            $table->timestamps();

            $table->unique(['activity_id', 'predecessor_activity_id', 'relationship']);
            $table->index('predecessor_activity_id');
        });

        /*
         * **The activity identifier §13 says an RFI, a submittal and a variation must be able to point at.**
         *
         * Added here rather than in the migrations that built those registers, and that was the plan: a nullable integer
         * nothing could populate for three sub-phases reads like an unfinished feature, and one migration with real
         * foreign keys is cheaper than three with none.
         *
         * **Punch items deliberately get no such column**, which corrects a note left in the plan at 9c: §16.4 does not
         * ask for one, and the reason holds — a snag is located in *space*, which is what its location and grid
         * reference are for, and the activity that built the thing is finished by definition. An activity link there
         * would be a field nobody could fill in truthfully.
         */
        Schema::table('construction_rfis', function (Blueprint $table) {
            $table->foreignId('activity_id')->nullable()->after('drawing_document_id')
                ->constrained('construction_activities')->nullOnDelete();
            $table->index('activity_id');
        });

        Schema::table('construction_submittals', function (Blueprint $table) {
            $table->foreignId('activity_id')->nullable()->after('document_id')
                ->constrained('construction_activities')->nullOnDelete();
            $table->index('activity_id');
        });

        /*
         * §13's third requirement: "somewhere to hang a delay event".
         *
         * Which activity was delayed is what turns a claim into an assessable one — an event against a job is a
         * complaint, and an event against an activity with a baseline finish is an argument about a date.
         */
        Schema::table('construction_delay_events', function (Blueprint $table) {
            $table->foreignId('activity_id')->nullable()->after('contract_id')
                ->constrained('construction_activities')->nullOnDelete();
            $table->index('activity_id');
        });
    }

    public function down(): void
    {
        Schema::table('construction_delay_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activity_id');
        });

        Schema::table('construction_submittals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activity_id');
        });

        Schema::table('construction_rfis', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activity_id');
        });

        Schema::dropIfExists('construction_activity_predecessors');
        Schema::dropIfExists('construction_activities');
    }
};
