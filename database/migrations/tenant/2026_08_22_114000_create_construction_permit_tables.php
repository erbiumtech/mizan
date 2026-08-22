<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permits to work — `docs/construction-management-plan.md` §17.5.
 *
 * **"A permit is time-boxed, and an expired-but-open permit is the failure mode that kills people."** That sentence is
 * why `valid_from` and `valid_to` are **datetimes** rather than dates, and why the register's first duty is to show what
 * is past its window and still open. A permit valid "on the 20th" authorises hot work at four in the morning; a permit
 * valid until 17:00 on the 20th does not.
 *
 * **An extension is a new row pointing back at the one it extends.** §17.5 is explicit: "overwriting `valid_to`
 * destroys the record of what was authorised when." A regulator or an insurer asks what was authorised *at the moment
 * something happened*, and a mutated end time cannot answer it — the same reasoning §8 freezes certificate terms with
 * and §13 freezes notice days with, applied to a document where the consequence is a fire rather than a dispute.
 *
 * **`details` is JSON and the shared fields are real columns.** §17.5 draws that line deliberately: gas readings, an
 * isolation certificate reference, a rescue plan and wind limits are genuinely per-type and belong in a bag, while
 * location, activity, validity, persons and the issue stamps are asked of every permit and have to be queryable. A
 * schema with thirteen types' fields as columns would be ninety mostly-null columns; a schema with everything in JSON
 * could not answer "what is open on level four right now".
 *
 * The suspension fields are separate from the status for the same reason a punch item's rejection is: a permit suspended
 * because the wind got up and then resumed is a different history from one that ran uninterrupted, and a single status
 * column loses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_permits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id')->nullable();

            $table->string('permit_number');

            /*
             * The thirteen types §17.5 names.
             *
             * An enum rather than a lookup table because the list is a *safety* vocabulary rather than project reference
             * data: a company does not invent a fourteenth kind of high-risk work, and the ones here map onto the
             * procedures every regime writes.
             */
            $table->enum('type', [
                'hot_work', 'confined_space', 'working_at_height', 'excavation', 'electrical_isolation',
                'lifting_operation', 'road_closure', 'live_services', 'demolition', 'radiography',
                'night_work', 'diving', 'pressure_testing',
            ]);

            $table->text('description');
            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();
            $table->string('location_detail')->nullable();
            $table->text('activity')->nullable();

            /*
             * **Datetimes, and the reason is the whole of §17.5.**
             *
             * A permit valid "on the 20th" authorises hot work at four in the morning. A permit valid until 17:00 on the
             * 20th does not, and the difference is the failure mode.
             */
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('requester_label')->nullable();
            $table->unsignedInteger('persons_count')->nullable();

            /*
             * **The genuinely type-specific fields.** See the class docblock for why this is a bag and the rest are not.
             */
            $table->json('details')->nullable();

            // Issued by us, accepted by whoever is doing the work — two stamps, because a permit nobody accepted is a
            // piece of paper rather than an authorisation.
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('accepted_by_label')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->enum('status', ['draft', 'issued', 'suspended', 'closed', 'cancelled'])->default('draft');

            /*
             * Suspension, kept apart from the status's history.
             *
             * A permit suspended because the wind got up and then resumed is a different history from one that ran
             * uninterrupted, and a status column alone loses it.
             */
            $table->timestamp('suspended_at')->nullable();
            $table->unsignedBigInteger('suspended_by')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamp('resumed_at')->nullable();

            /*
             * Close-out, and **`area_made_safe` is the column the closure is for.**
             *
             * A hot-work permit closed without the area having been checked is the sequence that burns a building down
             * an hour after everybody goes home. Recorded as its own flag rather than implied by the closure, because
             * "closed" is a state and "we walked it and it is safe" is an assertion somebody makes.
             */
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->boolean('area_made_safe')->default(false);
            $table->text('close_out_notes')->nullable();
            $table->text('cancel_reason')->nullable();

            /*
             * **An extension is a new row pointing back at the one it extends** (§17.5).
             *
             * Overwriting `valid_to` destroys the record of what was authorised when — and a regulator asks what was
             * authorised at the moment something happened.
             */
            $table->foreignId('extends_permit_id')->nullable()
                ->constrained('construction_permits')->nullOnDelete();

            $table->timestamps();

            $table->unique(['job_id', 'permit_number']);
            $table->index(['job_id', 'status']);
            // The register's first duty: what is past its window and still open.
            $table->index(['status', 'valid_to']);
            $table->index(['job_id', 'type', 'status']);
            $table->index('extends_permit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_permits');
    }
};
