<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection and test plans, to ISO 9001 — `docs/construction-management-plan.md` §17.1.
 *
 * **`point_type` is the entire reason an ITP exists**, and §17.1 says so in as many words: "a **hold** point means work
 * may not proceed past it; a **witness** point means a party is invited and work may proceed if they do not attend; a
 * **review** point is documentation only. Collapsing them into a checkbox turns the document into a formality."
 *
 * Everything else on these tables follows from taking that seriously:
 *
 *  - **`construction_itp_activity_parties` is a pivot rather than a column**, because "who must attend" is exactly the
 *    question a hold point answers, and one column cannot say *the Engineer witnesses, a third-party laboratory
 *    verifies, the Employer approves*.
 *  - **`notice_hours` is on the ITP row**, because a hold point with no notice period is a hold point that stops work
 *    the day somebody remembers it. The inspection snapshots it at request time.
 *  - **`released_hold_point` is a real column with a releaser and a date.** §17.1: "the whole function of a hold point
 *    is that work may not proceed past it; a hold point that releases nothing and blocks nothing is a checkbox with
 *    extra steps."
 *  - **The point type is snapshotted onto the inspection.** An ITP is revised — a point becomes a witness where it was a
 *    hold — and an inspection carried out under the old plan was carried out under the old rules. Reading the current
 *    plan would retroactively change what an inspection meant, which is the failure §8's certificate snapshots and §13's
 *    notice-day snapshot both exist to prevent.
 *
 * `construction_inspection_checks` is the check sheet: expected against actual, with a unit and a pass flag. Real
 * values rather than a prose result, because "concrete cube at 28 days" is a number somebody compares with a standard,
 * and a paragraph is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_itps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            // Unconstrained for the licensing reason §18 sets out: this module requires only `construction`.
            $table->unsignedBigInteger('contract_id')->nullable();

            $table->string('reference')->comment('ITP-CIV-001 — quoted in correspondence and on the check sheet');
            $table->string('title');
            $table->text('scope')->nullable();

            // Which trade or discipline. A string, because every project issues its own list.
            $table->string('discipline')->nullable();

            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();

            /*
             * The plan's own revision and approval.
             *
             * An ITP is a controlled document: a certification body asks which revision was in force when a given
             * inspection was carried out, and "the current one" is not an answer.
             */
            $table->string('revision')->nullable();
            $table->enum('status', ['draft', 'issued', 'approved', 'superseded'])->default('draft');
            $table->date('issued_on')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->date('approved_on')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'reference']);
            $table->index(['job_id', 'status']);
        });

        Schema::create('construction_itp_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('itp_id')->constrained('construction_itps')->cascadeOnDelete();

            $table->unsignedInteger('sequence')->default(0);
            $table->text('activity_description');

            // The standard and the criterion are what an inspection is judged against, and they are separate fields
            // because a clause reference is not an acceptance value.
            $table->string('reference_standard')->nullable()->comment('BS EN 206, ACI 318, the specification clause');
            $table->text('acceptance_criteria')->nullable();
            $table->string('inspection_method')->nullable();
            $table->string('frequency')->nullable()->comment('Every pour, one in twenty, 100%');
            $table->string('record_form')->nullable();

            /*
             * **The distinction that is the whole document** (§17.1).
             *
             * `hold` stops work. `witness` invites a party and proceeds without them. `review` is paperwork.
             * `surveillance` and `monitor` are the two lighter forms a client's quality plan usually also names.
             */
            $table->enum('point_type', ['hold', 'witness', 'review', 'surveillance', 'monitor'])->default('review');

            /*
             * How much warning the attending party is owed.
             *
             * On the ITP row rather than in configuration, because it is a term of *this* plan for *this* activity —
             * 24 hours for a rebar inspection and a week for a third-party load test.
             */
            $table->unsignedInteger('notice_hours')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['itp_id', 'sequence']);
            // The register's most-asked question: which of this plan's points stop work.
            $table->index('point_type');
        });

        /*
         * **Who must attend — a pivot, not a column** (§17.1).
         *
         * One column cannot say *the Engineer witnesses, a third-party laboratory verifies, the Employer approves*, and
         * that sentence is precisely what a hold point on a structural pour means.
         */
        Schema::create('construction_itp_activity_parties', function (Blueprint $table) {
            $table->id();

            $table->foreignId('itp_activity_id')->constrained('construction_itp_activities')->cascadeOnDelete();

            $table->enum('party', [
                'contractor', 'engineer', 'employer', 'consultant', 'subcontractor',
                'third_party_lab', 'authority', 'other',
            ]);

            /*
             * What that party does at this point, which is not the same as the point's own type.
             *
             * A hold point can require the Engineer to *approve* and a laboratory to *verify* — two parties, two roles,
             * one point. Folding the role into the party would lose which of them the work is actually waiting on.
             */
            $table->enum('role', ['performs', 'witnesses', 'verifies', 'approves', 'informed'])->default('witnesses');

            $table->boolean('attendance_mandatory')->default(false);
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('party_label')->nullable();

            $table->timestamps();

            $table->unique(['itp_activity_id', 'party', 'role'], 'itp_activity_parties_unique');
        });

        Schema::create('construction_inspections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            /*
             * The ITP row this came from, **nullable because ad-hoc inspections exist** (§17.1).
             *
             * A client's representative walking the site and asking to see a detail is an inspection with no plan row
             * behind it, and a register that could not record one would push it off the system.
             */
            $table->foreignId('itp_activity_id')->nullable()
                ->constrained('construction_itp_activities')->nullOnDelete();

            $table->string('reference');

            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();
            $table->text('activity_description');
            // Both unconstrained: contract items belong to `construction_contracts` and activities to
            // `construction_field`, and this module requires neither.
            $table->unsignedBigInteger('contract_item_id')->nullable();
            $table->unsignedBigInteger('activity_id')->nullable();

            /*
             * **The point type, snapshotted at request time** (§17.1).
             *
             * An ITP gets revised and a hold point becomes a witness point. An inspection carried out under the old plan
             * was carried out under the old rules, and reading the current plan would retroactively change what it
             * meant — the same reasoning §8 freezes retention terms with and §13 freezes notice days with.
             */
            $table->enum('point_type', ['hold', 'witness', 'review', 'surveillance', 'monitor'])->default('review');
            $table->unsignedInteger('notice_hours')->nullable();

            $table->date('requested_on');
            $table->unsignedBigInteger('requested_by')->nullable();
            // When the attending party was actually told, which is what a notice period is measured from.
            $table->date('notified_on')->nullable();
            $table->date('scheduled_for')->nullable();
            $table->date('inspected_on')->nullable();

            $table->enum('status', [
                'requested', 'scheduled', 'passed', 'passed_with_comments', 'failed', 'cancelled',
            ])->default('requested');

            $table->unsignedBigInteger('inspected_by')->nullable();
            $table->foreignId('witness_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('witness_label')->nullable();

            /*
             * **Whether the witness turned up**, which is the fact a witness point is defined by.
             *
             * §17.1: a witness point means "a party is invited and work may proceed if they do not attend". So the
             * register has to be able to say that they were invited and did not come — which is the contractor's
             * protection, and it is lost if attendance is inferred from a name being filled in.
             */
            $table->boolean('witness_attended')->default(false);

            $table->text('result_notes')->nullable();
            // Set by §17.2's register when an inspection fails; unconstrained until that table exists.
            $table->unsignedBigInteger('ncr_id')->nullable();

            /*
             * **The hold-point release** (§17.1).
             *
             * A hold point that releases nothing and blocks nothing is a checkbox with extra steps. So the release is a
             * column with a name and a date against it, and it is a separate act from recording the result — passing an
             * inspection and authorising the next operation to start are two decisions, and on a certified site they are
             * two people.
             */
            $table->boolean('released_hold_point')->default(false);
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_notes')->nullable();

            $table->timestamps();

            $table->unique(['job_id', 'reference']);
            $table->index(['job_id', 'status']);
            // The register's load-bearing query: hold points that have passed and not been released, and hold points
            // that have not been inspected at all. Work is standing still on both.
            $table->index(['point_type', 'status', 'released_hold_point'], 'inspections_type_status_released_index');
            $table->index('itp_activity_id');
        });

        /*
         * The check sheet — **real values, not prose**.
         *
         * "Concrete cube at 28 days" is a number somebody compares with a standard. A paragraph saying it looked fine is
         * not a record, and the difference is what a certification body reads.
         */
        Schema::create('construction_inspection_checks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inspection_id')->constrained('construction_inspections')->cascadeOnDelete();

            $table->unsignedInteger('sequence')->default(0);
            $table->text('description');
            $table->string('expected_value')->nullable();
            $table->string('actual_value')->nullable();
            $table->string('unit', 32)->nullable();

            /*
             * Pass, fail, or **not yet decided** — hence nullable rather than a boolean default.
             *
             * A check sheet is filled in as the inspection proceeds. A `false` default would make every unfilled line
             * read as a failure, and an inspection that failed on lines nobody looked at is worse than no record.
             */
            $table->boolean('passed')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['inspection_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_inspection_checks');
        Schema::dropIfExists('construction_inspections');
        Schema::dropIfExists('construction_itp_activity_parties');
        Schema::dropIfExists('construction_itp_activities');
        Schema::dropIfExists('construction_itps');
    }
};
