<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests for information — `docs/construction-management-plan.md` §16.2.
 *
 * An RFI is a question the contractor cannot build without an answer to. The register's whole value is the two things
 * §16.2 designs it around, and both are in the columns rather than in a workflow.
 *
 * **`ball_in_court` is a role *and* a person, "both, deliberately".** §16.2: "the useful report is 'seventeen RFIs
 * sitting with the Architect' and the useful email goes to a named individual". A role alone cannot be written to; a
 * contact alone cannot be counted, because the individual changes three times over a two-year job and the role does
 * not. Storing one and deriving the other loses whichever question was not chosen.
 *
 * **The impact is a flag at raise time, not an amount.** `none|possible|yes` with a nullable estimate beside it,
 * because §16.2 is explicit: "at raise time nobody knows, and forcing a number produces a column of zeros that later
 * reads as *no impact* when it meant *not yet assessed*". That distinction is the difference between a register that
 * finds money and one that launders an absence.
 *
 * **`rfi_number` is per job and has no gaps**, which is a rule about deletion rather than about numbering: the register
 * is a document somebody reads sequentially, and "where is RFI 14?" must never be answerable with "somebody removed
 * it". So nothing is ever deleted — an RFI that no longer needs answering is *cancelled* and keeps its number, with
 * the reason on the row.
 *
 * **`delay_event_id` is not in §16.2, and it is the most valuable column here.** Late information is already a cause
 * category on §13's delay event, and an RFI is the commonest place a late-information delay is *first written down*. So
 * an RFI carrying `time_impact_flag = yes` with no delay event behind it is a notice period running with nothing
 * chasing it — the same silent loss §13 says is "the single most common way a contractor donates money", reached from
 * the register where the evidence already sits. The null is the finding, exactly as it is on the diary's events and its
 * dockets.
 *
 * Two columns §16.2 lists are deliberately **not** here. `activity_id` — which programme activity this blocks — waits
 * for the programme sub-phase, which builds `construction_activities` and adds the column with a real foreign key to
 * RFIs, submittals and punch items together; a nullable integer nothing can populate for three sub-phases is dead
 * weight that reads like an unfinished feature. And there is **no per-round response table**, which submittals get and
 * this does not: §16.3 needs one because "a submittal that has been round three times is a schedule risk", whereas an
 * RFI answered unsatisfactorily is re-raised as a new numbered question — which is what the register should show,
 * because the second question has its own clock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_rfis', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            // Which contract it arises under, unconstrained for the licensing reason §13's `contract_id` sets out:
            // `construction_field` requires only `construction`, so this module reads an integer and never names
            // `Contract`. An RFI on a job whose commercial side is kept elsewhere is still an RFI.
            $table->unsignedBigInteger('contract_id')->nullable();

            // Per job, and gapless because nothing is deleted. See the class docblock.
            $table->string('rfi_number');

            $table->string('subject');
            $table->text('question');

            /*
             * **The proposed solution, and it is the field that gets answers fast.**
             *
             * A question with a proposal attached is a yes-or-no; a question without one is homework for somebody who
             * did not ask for it. Nullable because it is not always possible to propose anything, but the form asks.
             */
            $table->text('proposed_solution')->nullable();

            // A string rather than an enum, for the same reason §15's naming convention keeps discipline codes as
            // strings: every project issues its own list, and an enum fails on the second job.
            $table->string('discipline')->nullable();

            // Where and what it is about. Both constrained: `construction_locations` (§16.5) and
            // `construction_documents` (§15) are in the spine, which this module requires.
            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();
            $table->foreignId('drawing_document_id')->nullable()
                ->constrained('construction_documents')->nullOnDelete();
            $table->string('specification_reference')->nullable();

            $table->unsignedBigInteger('raised_by')->nullable();
            $table->date('raised_on');

            /*
             * When the answer is needed, which is what makes the register a clock rather than a list.
             *
             * Nullable, because an RFI raised months ahead of the work genuinely has no date yet — but the register
             * reports how many have none, since an RFI with no required-by is one nobody is chasing.
             */
            $table->date('required_by')->nullable();

            /*
             * **Whose court the ball is in — the role and the person, both.**
             *
             * The role is what a report counts. The contact is who an email goes to. See the class docblock for why
             * neither is derivable from the other.
             */
            $table->enum('ball_in_court', [
                'contractor', 'architect', 'engineer', 'employer', 'consultant', 'subcontractor', 'supplier', 'other',
            ])->default('architect');
            $table->foreignId('ball_in_court_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->enum('status', ['draft', 'open', 'answered', 'closed', 'cancelled'])->default('open');

            /*
             * **Flags, not amounts** (§16.2). `possible` is the value that carries the information: it says somebody
             * has looked and cannot yet say, which is a different fact from `none` and from an unfilled column.
             *
             * The estimates sit beside them and stay null until there is one. A register showing `possible` with no
             * estimate for four months is a register with something to chase.
             */
            $table->enum('cost_impact_flag', ['none', 'possible', 'yes'])->default('none');
            $table->decimal('cost_impact_estimate', 15, 2)->nullable();
            $table->enum('time_impact_flag', ['none', 'possible', 'yes'])->default('none');
            $table->unsignedInteger('time_impact_days')->nullable();

            // The answer. `answered_on` is the day it was given, not the day somebody transcribed it — the response
            // time a register reports is the other side's, and dating it from the transcription flatters them.
            $table->text('answer')->nullable();
            $table->date('answered_on')->nullable();
            $table->foreignId('answered_by_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->unsignedBigInteger('answer_recorded_by')->nullable();
            $table->foreignId('answer_document_id')->nullable()
                ->constrained('construction_documents')->nullOnDelete();

            $table->date('closed_on')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            // Cancelled rather than deleted, so the numbering stays gapless and the register can say why.
            $table->text('cancel_reason')->nullable();

            /*
             * **The variation this question became**, unconstrained because `construction_variations` belongs to
             * `construction_contracts`.
             *
             * §16.2's last column, and the one that closes the loop: the answer to "can we use a 200 lintel instead"
             * is often an instruction, and an RFI that became a change with nothing linking the two leaves the
             * variation with no origin and the RFI looking like a question that cost nothing.
             */
            $table->unsignedBigInteger('variation_id')->nullable();

            /*
             * **The delay event this RFI's time impact was notified under** — not in §16.2, and the column this
             * register earns its place with. The null is the exposure; see the class docblock.
             *
             * Constrained, unlike the variation, because §13's events are in this same module.
             */
            $table->foreignId('delay_event_id')->nullable()
                ->constrained('construction_delay_events')->nullOnDelete();

            $table->timestamps();

            $table->unique(['job_id', 'rfi_number']);
            $table->index(['job_id', 'status']);
            // "Seventeen RFIs sitting with the Architect", and "what is overdue" — the register's two reports.
            $table->index(['ball_in_court', 'status']);
            $table->index(['required_by', 'status']);
            $table->index('delay_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_rfis');
    }
};
