<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toolbox talks, the induction register and competencies — `docs/construction-management-plan.md` §17.5.
 *
 * **"In both tables a person's name must work without an employee record, because most attendees on most sites are a
 * subcontractor's labourers."** That is the sentence these three tables are shaped by, and it is the same one §17.3
 * makes about an injured person. An induction register that required an employee record would record the inductions of
 * the people who happened to be on the payroll — which is a small and unrepresentative subset of the people on site.
 *
 * **`construction_site_personnel` is the induction register**, and the pair of columns that makes it one is
 * `inducted_on` with `induction_valid_to`: an induction is not a permanent state. Somebody inducted fourteen months ago
 * on a site whose induction lasts a year is not inducted, and a register that could not say so would report full
 * coverage on a site with none.
 *
 * **`construction_competencies` carries the tickets**, and `expiry_notified_at_days` is copied from
 * `employee_documents` and `construction_compliance_items` for the same reason both have it: it records *which* warning
 * has already been sent, so a daily run warns once per threshold rather than every day until somebody acts. Whoever
 * receives a warning every morning stops reading them, and that is the failure the column prevents.
 *
 * **`is_mandatory` is the column that turns an expiry into a stoppage.** A first-aid certificate lapsing is a gap; a
 * confined-space ticket lapsing on somebody who is in a chamber this morning is an emergency, and only the flag can
 * tell them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_toolbox_talks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            $table->string('reference');
            $table->string('topic');
            $table->text('content_summary')->nullable();

            // A datetime: a talk given at seven in the morning before the shift and one given at four in the afternoon
            // are different facts about a site, and the second is usually a talk given to a tick-box.
            $table->timestamp('delivered_at');
            $table->unsignedBigInteger('delivered_by')->nullable();
            $table->string('presenter_label')->nullable();

            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();
            $table->unsignedInteger('duration_minutes')->nullable();

            /*
             * What prompted it, which is what makes a talk a *response* rather than a routine.
             *
             * A talk given after an incident, against an NCR, or before a particular high-risk operation is worth
             * knowing about as such — and a polymorphic link would have been the obvious shape and the wrong one, since
             * a talk frequently arises from something outside this application altogether. So it is a free-text prompt
             * plus an optional incident, which is the one link worth being able to query.
             */
            $table->string('prompted_by')->nullable();
            $table->foreignId('incident_id')->nullable()->constrained('construction_incidents')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'reference']);
            $table->index(['job_id', 'delivered_at']);
        });

        /*
         * **The induction and competency register.**
         *
         * One row per person per job, because a person inducted on the tower is not inducted on the annexe — and a site
         * that treated one induction as covering every job would be a site whose gate register means nothing.
         */
        Schema::create('construction_site_personnel', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            // **The name works alone**, and the employee link is the exception. See the class docblock.
            $table->string('name');
            $table->string('employer')->nullable();
            $table->string('trade')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('identification')->nullable()->comment('Whatever the site checks: a card number, a CNIC');
            $table->string('phone')->nullable();

            /*
             * **An induction is not a permanent state.**
             *
             * Somebody inducted fourteen months ago on a site whose induction lasts a year is not inducted, and a
             * register that could not say so would report full coverage on a site with none.
             */
            $table->date('inducted_on')->nullable();
            $table->unsignedBigInteger('inducted_by')->nullable();
            $table->date('induction_valid_to')->nullable();

            $table->date('first_on_site')->nullable();
            $table->date('last_on_site')->nullable();

            // Whether they are currently expected on site, which is what makes an expired ticket urgent rather than
            // historical.
            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'is_active']);
            $table->index(['job_id', 'name']);
            $table->index('induction_valid_to');
        });

        Schema::create('construction_competencies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_personnel_id')->constrained('construction_site_personnel')->cascadeOnDelete();

            $table->enum('kind', ['training', 'licence', 'certification', 'medical', 'authorisation'])
                ->default('training');
            $table->string('title');
            $table->string('reference')->nullable()->comment('The certificate or licence number');
            $table->string('issuing_body')->nullable();

            $table->date('issued_on')->nullable();
            /*
             * Nullable, because some things genuinely do not expire — and a required expiry would make people type a
             * date they do not have, which is worse than an honest blank.
             */
            $table->date('expires_on')->nullable();

            /*
             * **The flag that turns an expiry into a stoppage.**
             *
             * A first-aid certificate lapsing is a gap; a confined-space ticket lapsing on somebody who is in a chamber
             * this morning is an emergency. Only this column tells them apart.
             */
            $table->boolean('is_mandatory')->default(false);

            /*
             * Which warning has already gone out, copied from `employee_documents` and
             * `construction_compliance_items` for the reason both give: a daily run must warn *once per threshold*
             * rather than every morning until somebody acts. Whoever gets a warning every day stops reading them.
             */
            $table->smallInteger('expiry_notified_at_days')->nullable();

            $table->string('document_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('site_personnel_id');
            // The exposure query: mandatory tickets, expiring or expired.
            $table->index(['is_mandatory', 'expires_on']);
        });

        Schema::create('construction_toolbox_talk_attendees', function (Blueprint $table) {
            $table->id();

            $table->foreignId('toolbox_talk_id')->constrained('construction_toolbox_talks')->cascadeOnDelete();

            /*
             * The register row where there is one, and a plain name where there is not.
             *
             * Both, because the useful report is "who on this site has had the working-at-height talk" — which needs the
             * register — while the useful *form* is one a foreman can fill in at seven in the morning for a gang half of
             * whom arrived that day.
             */
            $table->foreignId('site_personnel_id')->nullable()
                ->constrained('construction_site_personnel')->nullOnDelete();
            $table->string('name');
            $table->string('employer')->nullable();
            $table->boolean('signed')->default(false);

            $table->timestamps();

            $table->index('toolbox_talk_id');
            $table->index('site_personnel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_toolbox_talk_attendees');
        Schema::dropIfExists('construction_competencies');
        Schema::dropIfExists('construction_site_personnel');
        Schema::dropIfExists('construction_toolbox_talks');
    }
};
