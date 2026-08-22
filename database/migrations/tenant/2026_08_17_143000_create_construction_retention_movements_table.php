<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention is a ledger, not a balance — `docs/construction-management-plan.md` §11.
 *
 * **This is consistent with the computed-not-stored rule rather than an exception to it: the balance is still
 * computed** as the sum of movements. What is stored is the *events*, and they genuinely are not derivable from
 * certificates the moment any of the following happens — all of them ordinary:
 *
 *  - a bond substitutes for cash;
 *  - sectional taking-over releases early on part of the works, which FIDIC 14.9 explicitly contemplates;
 *  - part is forfeited against uncorrected defects;
 *  - the parties agree a one-off adjustment.
 *
 * Those are decisions, not arithmetic. A retention figure derived from certificates alone cannot express any of
 * them, and a company that has done one of them has a balance nobody can explain at final account.
 *
 * `basis_gross` and `rate_applied` are snapshotted so a movement is reproducible years later without re-deriving
 * anything from live data, and `cap_reached` is why a movement is smaller than rate × basis — a question somebody
 * asks exactly once per job, at the worst possible moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_retention_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('construction_contracts')->cascadeOnDelete();

            // Held movements name a certificate; releases often do not (§11).
            $table->foreignId('payment_certificate_id')->nullable()
                ->constrained('construction_payment_certificates')->nullOnDelete();

            $table->enum('kind', ['held', 'released', 'forfeited', 'substituted_by_bond', 'reinstated', 'adjusted']);
            $table->enum('stage', ['interim', 'first_release', 'final_release', 'early_release'])->default('interim');

            /*
             * **Signed**, one convention across the module: positive is held, negative is released or forfeited.
             * The balance is the sum, so a release that arrived positive would increase the money being withheld
             * while every screen called it a release.
             */
            $table->decimal('amount', 15, 2);

            // Snapshotted so the movement is reproducible without re-reading a certificate that may since have
            // been voided or a contract term somebody has renegotiated.
            $table->decimal('basis_gross', 15, 2)->nullable();
            $table->decimal('rate_applied', 5, 2)->nullable();
            $table->boolean('cap_reached')->default(false);

            // Computed at the time of writing, because the trigger dates move: an extension of time moves
            // completion, which moves the defects period, which moves the second release.
            $table->date('due_on')->nullable();
            $table->date('released_on')->nullable();

            // Guarded, like the certificate's: without Invoicing there is no invoice to point at and the
            // register is a contractual record rather than a financial one (§18).
            $table->unsignedBigInteger('invoice_id')->nullable();

            // The deduction row on the certificate this movement corresponds to, which is what lets the
            // reconciliation of §11 compare the two registers row by row rather than in total.
            $table->foreignId('certificate_deduction_id')->nullable()
                ->constrained('construction_certificate_deductions')->nullOnDelete();

            /*
             * The bond or guarantee that substituted for cash. Nullable and unconstrained: the securities
             * register is a later table, and the column is here now so a substitution has somewhere to point
             * rather than being retrofitted — exactly how `construction_jobs.project_id` is handled.
             */
            $table->unsignedBigInteger('security_id')->nullable();

            // Mandatory in the service for a forfeit or an adjustment: those are decisions, and a decision with
            // no recorded reason is one nobody can defend at final account.
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();

            $table->timestamps();

            $table->index(['contract_id', 'kind']);
            $table->index(['contract_id', 'stage']);
            $table->index('due_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_retention_movements');
    }
};
