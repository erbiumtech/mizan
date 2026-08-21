<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The submittal register — `docs/construction-management-plan.md` §16.3.
 *
 * A submittal is something the contractor has to get approved before it can be built or bought: a shop drawing, a
 * product data sheet, a sample, a method statement. The register exists for one arithmetic fact, and §16.3 puts it
 * first: **the submit-by date is computed by working backwards from the required-on-site date.** Fabrication lead,
 * procurement lead, review period and buffer, subtracted from the day the thing has to be on site.
 *
 * "Typed, it goes stale the day the programme moves, and a stale submit-by date is worse than none." So the five
 * ingredients are stored and the answer never is. That single rule is what turns a list of paperwork into a schedule
 * control — and it produces the finding a real job needs on day one, which is that a great many submittals are
 * **already late before anybody has done anything wrong**, because nobody did the subtraction when the programme was
 * agreed.
 *
 * **`construction_submittal_reviews` is a row per round**, which §16.3 is equally firm about: "a submittal that has been
 * round three times is a schedule risk, and a single status column loses that fact completely". The review period was
 * budgeted once. The second and third rounds spend float nobody planned, and the register has to be able to say so.
 *
 * **The claim is in the round, not in the header, and that distinction is this sub-phase's.** The three exposures the
 * field module has surfaced so far — an unnotified diary event, a docket accounts never saw, an RFI with a stated time
 * impact — are all money somebody *else* owes. A late submittal usually is not: the contractor is the one who submits,
 * so its lateness is a risk to manage rather than a claim to notify. What *is* claimable is a reviewer who kept it
 * longer than the contract's review period, which is why the turnaround lives on the round beside the period it was
 * measured against.
 *
 * `activity_id` is deliberately absent, for the same reason as on §16.2's RFI: the programme sub-phase builds
 * `construction_activities` and adds the column with a real foreign key to RFIs, submittals and punch items together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_submittals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            // Unconstrained for the licensing reason §13's `contract_id` sets out: this module requires only
            // `construction` and must work with the contracts table absent from a company's licence.
            $table->unsignedBigInteger('contract_id')->nullable();

            /*
             * **The specification section, which §16.3 calls "the register's natural key".**
             *
             * `03 30 00` under MasterFormat, `E20` under CAWS, a bare `Section 7` on a small job. A string, because
             * every project issues its own classification and an enum fails on the second one — the same reasoning
             * §15's naming convention uses for its code lists.
             *
             * Organising rather than unique: one section routinely carries a shop drawing, a product data sheet and a
             * sample, which are three submittals and three clocks.
             */
            $table->string('spec_section');
            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('type', [
                'product_data', 'shop_drawing', 'sample', 'mock_up', 'calculation', 'certificate', 'test_report',
                'om_manual', 'warranty', 'as_built', 'method_statement', 'material_approval', 'qualification',
            ])->default('shop_drawing');

            // Who owes it — usually a subcontractor or a supplier. A Contact, which Invoicing owns, so the column stays
            // null without that module and the free-text name carries it.
            $table->foreignId('responsible_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('responsible_label')->nullable();

            /*
             * **The five figures the submit-by date is computed from** (§16.3), and the reason none of them is a
             * computed column.
             *
             * `required_on_site_date` is the programme's; the four durations are the trade's, the buyer's, the
             * contract's and the planner's respectively. Storing the *answer* instead would go stale the day any one of
             * them moved, and a stale submit-by date is worse than none because somebody trusts it.
             */
            $table->date('required_on_site_date')->nullable();
            $table->unsignedInteger('fabrication_lead_days')->default(0);
            $table->unsignedInteger('procurement_lead_days')->default(0);
            $table->unsignedInteger('review_period_days')->default(14);
            $table->unsignedInteger('buffer_days')->default(0);

            // The actuals. First submission and final approval on the header; every round is its own row.
            $table->date('submitted_on')->nullable();
            $table->date('approved_on')->nullable();

            /*
             * **A projection of the latest round, maintained by the service and never typed.**
             *
             * §16.3 asks for a status and then says a single status column loses the rounds — both are true, and the
             * resolution is that the rounds are the record and this is the index into them. A form that let somebody
             * set it directly would let the register disagree with its own history.
             */
            $table->enum('status', [
                'pending', 'submitted', 'under_review', 'approved', 'approved_as_noted',
                'revise_and_resubmit', 'rejected', 'closed',
            ])->default('pending');

            // Whatever the project uses — P01, A, 1. The current one; each round records the revision it reviewed.
            $table->string('revision')->nullable();

            $table->foreignId('document_id')->nullable()
                ->constrained('construction_documents')->nullOnDelete();

            /*
             * **Long lead, flagged by hand and not inferred.**
             *
             * A ninety-day fabrication is long lead on a six-month job and ordinary on a four-year one, so no threshold
             * in this application can decide it. What the flag buys is a register somebody can filter to the twenty
             * items that will stop the job, out of four hundred that will not.
             */
            $table->boolean('is_long_lead')->default(false);

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'spec_section']);
            $table->index(['job_id', 'status']);
            // The register's two reports: what has to be submitted next, and what will stop the job.
            $table->index(['required_on_site_date', 'status']);
            $table->index('is_long_lead');
        });

        /*
         * **A row per round** — §16.3's own words, and the fact a status column cannot hold.
         *
         * Round one is planned for. Rounds two and three are not, and they spend the float between the reviewer's
         * return and the fabricator's start. A submittal at round three on a long-lead item is the commonest way a
         * fabrication date is missed with every individual step looking reasonable.
         */
        Schema::create('construction_submittal_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('submittal_id')->constrained('construction_submittals')->cascadeOnDelete();

            $table->unsignedInteger('round')->default(1);
            $table->string('revision')->nullable()->comment('The revision this round reviewed');

            $table->foreignId('reviewer_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('reviewer_label')->nullable();

            $table->date('sent_on');
            $table->date('returned_on')->nullable();

            /*
             * **The period this round was measured against, copied onto the row.**
             *
             * A snapshot, deliberately, and the reason is the same one §8 gives for freezing a certificate's retention
             * terms: the contract's review period can be renegotiated, and a round whose overrun was computed against
             * fourteen days must keep saying fourteen. Recomputing it later against twenty-one would silently retire an
             * entitlement somebody has already relied on.
             */
            $table->unsignedInteger('review_period_days')->default(14);

            $table->enum('result', [
                'approved', 'approved_as_noted', 'revise_and_resubmit', 'rejected', 'for_record',
            ])->nullable();

            $table->text('comments')->nullable();

            /*
             * The delay event raised for this round's overrun, where somebody raised one.
             *
             * **On the round rather than on the submittal**, because this is the only claimable part of a submittal's
             * lateness: the contractor submits, so its own lateness is a risk it owns, whereas a reviewer who kept a
             * drawing thirty days against a fourteen-day period has taken sixteen days of somebody else's programme.
             */
            $table->foreignId('delay_event_id')->nullable()
                ->constrained('construction_delay_events')->nullOnDelete();

            $table->timestamps();

            $table->unique(['submittal_id', 'round']);
            $table->index('returned_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_submittal_reviews');
        Schema::dropIfExists('construction_submittals');
    }
};
