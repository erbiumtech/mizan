<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-conformance, CAPA and close-out — `docs/construction-management-plan.md` §17.2.
 *
 * **`disposition` "is the field that decides whether money changes hands"** — §17.2's own words. ISO 9001's control of
 * nonconforming output is exactly this choice: rework it, repair it, use it as is, reject and replace it, or ask the
 * client for a concession. *Use as is* and *concession requested* are the two that end in a negotiation about price, and
 * a register that recorded only "closed" would lose which of the five happened.
 *
 * **CAPA is two pairs of fields, deliberately.** §17.2: "ISO 9001:2015 dropped preventive action as a clause and every
 * construction client's quality manual still demands both; merging them produces NCRs whose *preventive action*
 * restates the fix." So corrective action has an owner and a due date and a done date, and preventive action has its
 * own — because the first is about this pour and the second is about the next forty.
 *
 * **Close-out points at a re-inspection**, not at a checkbox: `verification_inspection_id` is what makes a closure
 * evidence rather than an assertion.
 *
 * **And the money never moves by itself.** §17.2 is emphatic: "an NCR never deducts automatically. It *proposes*; the
 * certification service **offers** the deduction as a row on the certificate that a human confirms and signs for."
 * FIDIC 14.6 permits the Engineer to withhold; it does not require it. "A deduction appearing on a certificate that
 * nobody decided on is the fastest available route to a dispute, and it will be the contractor's dispute, because the
 * client's copy has already left the building."
 *
 * So `deduct_from_payment` is a *proposal* flag and `deduction_certificate_id` records which certificate a human
 * eventually put it on — a nullable column this module never writes, exactly as
 * `final_settlements.payslip_id` is a nullable column the settlement never writes. §17.2 names that precedent, and
 * `ModuleBoundaryTest`'s commentary on it is where the shape is recorded: *a proposal, not a posting, visible in the
 * import graph.*
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_ncrs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            // Unconstrained: `construction_contracts` is guarded, not required (§18).
            $table->unsignedBigInteger('contract_id')->nullable();

            $table->string('ncr_number');
            $table->unsignedBigInteger('raised_by')->nullable();
            $table->date('raised_on');

            /*
             * **Severity, and it is not a proxy for cost.**
             *
             * Critical means the nonconformity affects structural adequacy, safety or a statutory requirement — the
             * kind that stops a handover — where a major is a real defect somebody has to put right. Deriving it from
             * `cost_impact` would make a cheap structural defect look minor, which is the one direction that gets
             * somebody hurt.
             */
            $table->enum('severity', ['minor', 'major', 'critical'])->default('minor');
            $table->string('category')->nullable()->comment('Workmanship, materials, documentation, dimensional…');
            $table->enum('source', [
                'inspection', 'audit', 'client', 'self_identified', 'testing', 'supplier', 'other',
            ])->default('inspection');

            // Where it came from, and **the traceability back to the ITP row that the standard actually asks for**.
            $table->foreignId('inspection_id')->nullable()
                ->constrained('construction_inspections')->nullOnDelete();
            $table->foreignId('itp_activity_id')->nullable()
                ->constrained('construction_itp_activities')->nullOnDelete();

            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            // Both unconstrained: contract items are `construction_contracts`', activities are `construction_field`'s.
            $table->unsignedBigInteger('contract_item_id')->nullable();
            $table->unsignedBigInteger('activity_id')->nullable();

            $table->foreignId('responsible_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('responsible_label')->nullable();

            $table->text('description');
            /*
             * **What rule was broken**, kept apart from what happened.
             *
             * "The cover is 22 mm" is the description; "BS EN 1992 clause 4.4.1, 40 mm minimum" is the requirement. An
             * NCR that states only the first is an opinion, and the second is what makes it arguable — which is why
             * ISO 9001 asks for both and why merging them produces a register nobody can defend.
             */
            $table->text('requirement_breached')->nullable();

            /*
             * **ISO 9001's control of nonconforming output, and §17.2 calls it "the field that decides whether money
             * changes hands".**
             *
             * Nullable, because a freshly raised NCR has not been dispositioned yet — and a default of `rework` would
             * quietly decide the commercially significant question on every new row.
             */
            $table->enum('disposition', [
                'rework', 'repair', 'use_as_is', 'reject_and_replace', 'concession_requested',
            ])->nullable();
            $table->string('concession_reference')->nullable();
            $table->date('dispositioned_on')->nullable();
            $table->unsignedBigInteger('dispositioned_by')->nullable();

            /*
             * **CAPA, as two pairs.** See the class docblock: corrective action is about this pour, preventive action is
             * about the next forty, and merging them produces NCRs whose preventive action restates the fix.
             */
            $table->text('root_cause')->nullable();
            $table->string('root_cause_method')->nullable()->comment('Five whys, fishbone, 8D — how it was arrived at');

            $table->text('corrective_action')->nullable();
            $table->unsignedBigInteger('corrective_owner_id')->nullable();
            $table->string('corrective_owner_label')->nullable();
            $table->date('corrective_due_on')->nullable();
            $table->date('corrective_done_on')->nullable();

            $table->text('preventive_action')->nullable();
            $table->unsignedBigInteger('preventive_owner_id')->nullable();
            $table->string('preventive_owner_label')->nullable();
            $table->date('preventive_due_on')->nullable();
            $table->date('preventive_done_on')->nullable();

            /*
             * **Close-out points at the re-inspection**, which is what makes a closure evidence rather than an
             * assertion (§17.2).
             */
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->date('verified_on')->nullable();
            $table->foreignId('verification_inspection_id')->nullable()
                ->constrained('construction_inspections')->nullOnDelete();

            $table->enum('status', [
                'open', 'dispositioned', 'action_taken', 'verified', 'closed', 'void',
            ])->default('open');
            $table->date('closed_on')->nullable();
            $table->text('void_reason')->nullable();

            /*
             * The money, and none of it moves by itself.
             *
             * `cost_impact` is what putting it right is expected to cost. `deduct_from_payment` is a **proposal** —
             * somebody's view that this should be withheld — and `deduction_amount` is the figure proposed.
             * `deduction_certificate_id` records which certificate a human eventually put it on, and **nothing in this
             * module ever writes it**: the certification service offers the row and a person signs for it.
             *
             * `back_charge_id` is the other route, for a subcontractor's defect the main contractor put right. Both are
             * unconstrained because both tables belong to other modules.
             */
            $table->decimal('cost_impact', 15, 2)->nullable();
            $table->boolean('deduct_from_payment')->default(false);
            $table->decimal('deduction_amount', 15, 2)->nullable();
            $table->unsignedBigInteger('deduction_certificate_id')->nullable();
            $table->unsignedBigInteger('back_charge_id')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'ncr_number']);
            $table->index(['job_id', 'status']);
            $table->index(['job_id', 'severity', 'status']);
            // The proposal queue a certificate reads: proposed, not yet on a certificate.
            $table->index(['deduct_from_payment', 'deduction_certificate_id'], 'ncrs_deduct_certificate_index');
            $table->index('itp_activity_id');
            $table->index('inspection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_ncrs');
    }
};
