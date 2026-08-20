<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delay events and the notice clock — `docs/construction-management-plan.md` §13.
 *
 * **"The notice clock is the most valuable thing in this section."** §13 says why, and it is the reason Phase 9 builds
 * this before the diary, the RFIs, the submittals or the programme: *"A due-date with a notification attached is worth
 * more commercially than the entire programme: a valid claim lost to a missed notice is the single most common way a
 * contractor donates money, and it fails in absolute silence."*
 *
 * Absolute silence is the operative phrase. Every other failure in this suite leaves a wrong number somewhere a report
 * can find; this one leaves nothing at all — the event happened, nobody wrote to the Engineer inside the contractual
 * window, and the entitlement is simply gone. There is no figure to be wrong.
 *
 * **`notice_required_by` is stored; whether an event is time-barred is computed.** The due date is stored because it is
 * a snapshot of a contractual period as it stood when the event was raised — the same reasoning §8 gives for freezing a
 * certificate, and it means a later edit to the contract's notice period cannot silently move a deadline somebody has
 * already been warned about. Time-barred is derived from the dates against the date being asked about, because §12
 * settled that argument for compliance and it holds identically here: a stored status is a status that stops agreeing
 * with the dates underneath it.
 *
 * **`notice_days` is snapshotted onto the event too**, so the stored due date can be explained rather than merely
 * trusted. A due date with no visible basis is a date somebody will eventually recompute differently.
 *
 * **`concurrent_with_delay_event_id` is here from the start** because §13 says concurrency "is the whole argument in
 * most extension-of-time disputes". A schema that cannot express it forces the argument into a free-text field, where
 * no report can find it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The contract's own notice period, which is what §13's `occurred_on + contract notice days` reads.
         *
         * On `construction_contracts` rather than on the event's own module, because it is a contract term like
         * `payment_terms_days` and `certification_period_days` beside it — FIDIC 20.1's 28 days, NEC4's 8 weeks for a
         * compensation event, a bespoke subcontract's 7. Nullable, and null means "use the shipped default", which is
         * what a company that has not filled it in has always meant.
         */
        Schema::table('construction_contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('delay_notice_days')->nullable()->after('certification_period_days');
        });

        Schema::create('construction_delay_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            /*
             * The contract the notice is served under, **unconstrained on purpose**.
             *
             * `construction_field` requires only `construction` (§18), so this module cannot depend on the contracts
             * table existing in a licensed sense — the same treatment `construction_commitments.contract_id` gets, and
             * for the same reason. Null means the event is recorded against the job with the shipped notice period.
             */
            $table->unsignedBigInteger('contract_id')->nullable();

            // `DE-1` upward per job. A gap in the series is a question at adjudication, and "withdrawn on the 14th" is
            // an answer where a missing number is not — the same argument §8.3 makes for certificates.
            $table->string('reference');

            $table->string('title');
            $table->text('description')->nullable();

            /*
             * Who bears the risk, which is the first thing an assessment turns on: an employer-risk event buys time and
             * often money, a contractor-risk one buys neither, and a neutral one usually buys time alone.
             */
            $table->enum('cause_category', [
                'employer_risk', 'contractor_risk', 'neutral', 'force_majeure', 'weather', 'variation',
                'late_information', 'access', 'utility', 'statutory', 'strike', 'unforeseen_conditions',
            ]);

            $table->date('occurred_on');

            /*
             * **The clock.** Stored, computed at creation as `occurred_on + notice_days`, and never recomputed — see
             * the class docblock. `notice_days` is beside it so the date can be explained.
             */
            $table->date('notice_required_by');
            $table->unsignedSmallInteger('notice_days');

            $table->date('notice_given_on')->nullable();
            $table->foreignId('notice_document_id')->nullable()
                ->constrained('construction_documents')->nullOnDelete();

            // Particulars — the detailed submission that follows the notice, on its own clock in most contracts.
            $table->date('particulars_due_by')->nullable();
            $table->date('particulars_submitted_on')->nullable();

            $table->decimal('claimed_days', 8, 2)->nullable();
            $table->decimal('awarded_days', 8, 2)->nullable();
            $table->decimal('cost_claimed', 15, 2)->nullable();
            $table->decimal('cost_awarded', 15, 2)->nullable();

            $table->enum('status', [
                'open', 'notified', 'particulars_submitted', 'under_assessment',
                'determined', 'rejected', 'withdrawn',
            ])->default('open');

            // The determination: who decided, when, and on what grounds. A determination with no author is not one.
            $table->date('determined_on')->nullable();
            $table->unsignedBigInteger('determined_by')->nullable();
            $table->text('determination_reason')->nullable();

            /*
             * Where the time was granted through a variation instead of an award — nullable and unconstrained for the
             * same licensing reason as `contract_id`.
             */
            $table->unsignedBigInteger('variation_id')->nullable();

            /*
             * **Concurrency, which §13 calls "the whole argument in most extension-of-time disputes".** Self-referential
             * and nullable: an event delayed at the same time as another one may buy time and not money, or nothing at
             * all, depending on the contract and the jurisdiction. This suite records the fact and never decides it.
             */
            $table->foreignId('concurrent_with_delay_event_id')->nullable()
                ->constrained('construction_delay_events')->nullOnDelete();

            $table->text('notes')->nullable();

            /*
             * Which warning threshold has already been sent, so the nightly run only notifies when the answer changes.
             * Copied from `employee_documents.expiry_notified_at_days` by way of §12's compliance register, whose own
             * migration explains it.
             */
            $table->unsignedSmallInteger('notice_notified_at_days')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'reference']);
            $table->index(['job_id', 'status']);
            // The query the nightly run makes: open events with a due date approaching.
            $table->index(['status', 'notice_required_by']);
            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_delay_events');

        Schema::table('construction_contracts', function (Blueprint $table) {
            $table->dropColumn('delay_notice_days');
        });
    }
};
