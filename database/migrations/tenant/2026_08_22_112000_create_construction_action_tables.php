<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One actions table for all of QHSE — `docs/construction-management-plan.md` §17.4.
 *
 * **"Every QHSE object generates the same record — somebody must do something by a date and somebody else must verify
 * it."** And the argument for one table rather than four is the whole of §17.4: "four separate action tables produce four
 * *overdue actions* reports that never agree, and the safety manager's one genuinely useful screen — everything overdue,
 * from every source, in one list — becomes a four-way union nobody maintains."
 *
 * So this is polymorphic over NCRs, incidents, inspections, toolbox talks and audit findings, and `job_id` is
 * denormalised beside the morph because every report on it is per job and reaching the job through five different parent
 * types is five joins on every row of the one screen this table exists to produce.
 *
 * **The assignee is three columns, and the plain name has to work on its own.** A user, a contact, or free text — because
 * the person who has to fix the handrail is frequently a subcontractor's foreman who is in neither table, and §17.3 makes
 * the same point about an injured person: "pretending otherwise loses the record entirely". An action nobody can be
 * assigned to is an action nobody does.
 *
 * **Verification is separate from completion**, which is the second half of §17.4's sentence. "Done" is the assignee's
 * claim; "verified" is somebody else's confirmation, and an actions register that conflated them would let the person
 * who caused a finding close it.
 *
 * One tension worth naming rather than hiding: §17.2 puts corrective and preventive action *on the NCR* as fields,
 * because ISO 9001 asks for them there. This table is the working list. They are **not** mirrored into each other — a
 * mirror is two sources for one date — so the one-list screen assembles both and **prints which source each row came
 * from**, the same discipline §17.6 requires of an exposure-hours denominator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_actions', function (Blueprint $table) {
            $table->id();

            /*
             * The QHSE object that produced it.
             *
             * A morph rather than five nullable foreign keys: §17.4's point is that the *shape* is identical whatever
             * raised it, and five columns of which four are always null is four columns of noise on every row.
             */
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            // Denormalised, and deliberately: every report here is per job, and reaching it through five parent types
            // would be five joins on every row of the one screen this table exists for.
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            $table->text('description');

            /*
             * What kind of action it is.
             *
             * `containment` earns its place beside corrective: stopping the bleeding is not the same as fixing the cause,
             * and a register that could not distinguish them would report a site as having addressed something when all
             * it did was cordon it off.
             */
            $table->enum('action_type', [
                'corrective', 'preventive', 'containment', 'improvement', 'follow_up',
            ])->default('corrective');

            /*
             * **The assignee, three ways, and the label works alone.**
             *
             * A user for somebody on this system, a contact for a subcontractor with a record, and free text for the
             * foreman who is in neither — which on most sites is most people.
             */
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->foreignId('assigned_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('assignee_label')->nullable();

            $table->date('due_on')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');

            $table->enum('status', ['open', 'in_progress', 'done', 'verified', 'cancelled'])->default('open');

            // The assignee's claim.
            $table->date('completed_on')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->text('completion_notes')->nullable();

            /*
             * **Somebody else's confirmation**, and it is separate on purpose: an actions register that conflated done
             * with verified would let the person who caused a finding close it.
             */
            $table->date('verified_on')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->text('verification_notes')->nullable();

            $table->text('cancel_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            // The one screen: everything overdue on this job, from every source.
            $table->index(['job_id', 'status', 'due_on']);
            $table->index(['assigned_user_id', 'status']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_actions');
    }
};
