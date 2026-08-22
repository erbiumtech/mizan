<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidents, to ISO 45001 — `docs/construction-management-plan.md` §17.3.
 *
 * **Near miss is a first-class kind rather than a checkbox**, and §17.3 gives the reason: "near-misses reported per
 * lost-time injury is the leading indicator that predicts the next one". A near miss stored as a flag on an injury
 * record cannot be counted, because a near miss *has no injury record* — nobody was hurt. It has to be its own row in
 * the same register, or the ratio that predicts the next lost-time injury cannot be computed at all.
 *
 * **`occurred_at` is a datetime, not a date**, "because shift timing is half the analysis". Three quarters of the way
 * through a twelve-hour shift is a finding; the 14th of August is not.
 *
 * **`reported_at` sits beside it and the reporting delay is computed — and is itself a safety metric.** A site that
 * takes four days to report a first-aid case is a site where the next one is not reported at all, and the delay is
 * visible only because both stamps are kept.
 *
 * **`injured_person_name` is free text that must work on its own.** §17.3: "a subcontractor's labourer is not in this
 * system, and pretending otherwise loses the record entirely." The `employee_id` beside it is guarded on Employees and
 * is the exception rather than the rule on a real site.
 *
 * Witnesses and photographs are children, because both are lists and because a witness statement taken three weeks
 * later is worth a fraction of one taken on the day — which the register can only show if each carries its own date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_incidents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id')->nullable();

            $table->string('incident_number');

            /*
             * **The kinds, and `near_miss` is one of them.**
             *
             * The list runs from what did not hurt anybody to what killed somebody, and the ordering matters less than
             * the fact that all of it is one register: an organisation that keeps near misses somewhere else cannot
             * compute the ratio that predicts its next lost-time injury.
             */
            $table->enum('kind', [
                'near_miss', 'unsafe_act', 'unsafe_condition',
                'first_aid', 'medical_treatment', 'restricted_work', 'lost_time_injury', 'fatality',
                'property_damage', 'environmental', 'fire', 'security',
                'dangerous_occurrence', 'occupational_illness',
            ])->default('near_miss');

            /*
             * **A datetime, because shift timing is half the analysis** (§17.3).
             *
             * Hour ten of a twelve-hour shift, the first hour after a break, the last night of a run of nights — none of
             * that survives a date column.
             */
            $table->timestamp('occurred_at');

            /*
             * **And the reporting delay is itself a metric.**
             *
             * Kept as its own stamp rather than trusting `created_at`, because an incident entered a week later from a
             * paper form was *reported* on the day it was reported, not on the day somebody typed it.
             */
            $table->timestamp('reported_at')->nullable();
            $table->unsignedBigInteger('reported_by')->nullable();

            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();
            $table->string('location_detail')->nullable()->comment('Where exactly, in words — on the drawing or not');
            $table->text('activity_being_performed')->nullable();

            /*
             * Who it happened to.
             *
             * `injured_person_type` distinguishes our own employee from a subcontractor's, a visitor, a member of the
             * public and the case where nobody was hurt at all — which is what a near miss and property damage are.
             */
            $table->enum('injured_person_type', [
                'nobody', 'employee', 'subcontractor', 'visitor', 'public', 'other',
            ])->default('nobody');
            // Guarded on Employees, and unconstrained because that module is not required.
            $table->unsignedBigInteger('employee_id')->nullable();
            // **This has to work on its own** (§17.3). Most people on most sites are in no table here.
            $table->string('injured_person_name')->nullable();
            $table->string('injured_person_employer')->nullable();
            $table->unsignedInteger('injured_person_age')->nullable();

            $table->text('description');
            $table->text('immediate_action')->nullable();

            /*
             * The consequence, and `is_lost_time` is separate from `days_lost` on purpose.
             *
             * A lost-time injury with the days not yet known is the ordinary state for the first fortnight — somebody is
             * signed off and nobody knows for how long. Deriving the flag from a day count would classify it as a
             * medical-treatment case until the certificate arrived, which is exactly when the reportable clock is
             * running.
             */
            $table->boolean('is_lost_time')->default(false);
            $table->unsignedInteger('days_lost')->nullable();
            $table->unsignedInteger('restricted_days')->nullable();

            $table->string('treatment')->nullable()->comment('First aid on site, hospital, GP, none');
            $table->string('body_part')->nullable();
            $table->string('injury_type')->nullable()->comment('Laceration, fracture, sprain, burn…');
            $table->string('agency')->nullable()->comment('What did the harm: the machine, the surface, the substance');

            $table->text('immediate_cause')->nullable();
            $table->text('root_cause')->nullable();
            $table->string('root_cause_method')->nullable();
            $table->unsignedBigInteger('investigated_by')->nullable();
            $table->date('investigation_completed_on')->nullable();

            /*
             * The authority-reporting fields, and they are real columns because the question a regulator asks is
             * *whether* and *when*, and a system that could not answer it has failed at the only moment it mattered.
             */
            $table->boolean('reportable_to_authority')->default(false);
            $table->string('authority_name')->nullable()->comment('HSE, OSHA, the labour department');
            $table->string('authority_reference')->nullable();
            $table->date('reported_to_authority_on')->nullable();

            $table->enum('status', ['reported', 'under_investigation', 'closed'])->default('reported');
            $table->date('closed_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'incident_number']);
            $table->index(['job_id', 'kind']);
            $table->index(['job_id', 'occurred_at']);
            // The lagging indicators: lost-time cases in a period, and the near-miss ratio against them.
            $table->index(['job_id', 'is_lost_time', 'occurred_at']);
            $table->index('reportable_to_authority');
        });

        /*
         * Witnesses, with their own date.
         *
         * A statement taken on the day is worth a multiple of one taken three weeks later, and the register can only say
         * which it has if each row carries when it was taken.
         */
        Schema::create('construction_incident_witnesses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('incident_id')->constrained('construction_incidents')->cascadeOnDelete();

            // The name works alone here for the same reason it does on the incident itself.
            $table->string('name');
            $table->string('employer')->nullable();
            $table->string('contact_detail')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();

            $table->text('statement')->nullable();
            $table->date('statement_taken_on')->nullable();
            $table->unsignedBigInteger('taken_by')->nullable();

            $table->timestamps();

            $table->index('incident_id');
        });

        /*
         * Photographs, as files on rows — the same decision §16.4's punch item made, and for the same reason: these
         * belong to the incident's own record rather than to the ISO 19650 register, which exists so somebody can find
         * the current issue of a drawing.
         */
        Schema::create('construction_incident_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('incident_id')->constrained('construction_incidents')->cascadeOnDelete();

            $table->string('caption');
            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_mime')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->unsignedBigInteger('taken_by')->nullable();

            $table->timestamps();

            $table->index('incident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_incident_photos');
        Schema::dropIfExists('construction_incident_witnesses');
        Schema::dropIfExists('construction_incidents');
    }
};
