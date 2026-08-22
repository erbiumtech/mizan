<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subcontractor compliance — `docs/construction-management-plan.md` §12.
 *
 * **Status is computed and never stored, and §12 calls this the most dangerous silent failure on the payable side**: a
 * row with a stored `status = 'verified'` and an `expires_on` three months in the past pays a subcontractor with no
 * cover, and the screen says everything is fine. So there is no status column here at all. What is stored is the dates,
 * and the answer is derived from them against the date being asked about.
 *
 * **Compliance blocks certification rather than payment**, and §12 gives the reason: blocking at payment leaves "an
 * approved payable in the ledger that finance cannot pay", which is a worse state than a refusal, because the liability
 * already exists and the stuck payment has no owner. The rule lives in `CertificationService`, not in a form — "a rule
 * enforced only in a Filament form is a rule that a queue job, a console command or any future API bypasses in complete
 * silence".
 *
 * **The override is a permission plus a mandatory reason recorded on the certificate**, because "a system with no
 * override is a system people work around with a spreadsheet, and then the register is decorative".
 *
 * `expiry_notified_at_days` is copied straight from `employee_documents`, whose own migration explains it — "which
 * threshold has already been warned about, so the next run only notifies when the answer changes".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_compliance_documents', function (Blueprint $table) {
            $table->id();

            // Whose document it is. A subcontractor is a Contact, which Invoicing owns — guarded, like every other
            // contact reference in this suite, so the register works without that module and the column stays null.
            $table->unsignedBigInteger('contact_id')->nullable();

            /*
             * Nullable, because §12 makes the distinction explicitly: "some obligations are company-level and some
             * contract-specific". A public liability policy covers everything the subcontractor does; a lien waiver
             * covers one payment on one contract.
             */
            $table->foreignId('contract_id')->nullable()->constrained('construction_contracts')->nullOnDelete();

            $table->enum('kind', [
                // Insurance.
                'general_liability', 'workers_compensation', 'professional_indemnity', 'contract_works',
                // Waivers, which are per payment period.
                'lien_waiver_conditional_progress', 'lien_waiver_unconditional_progress',
                'lien_waiver_conditional_final', 'lien_waiver_unconditional_final',
                // Everything else §12 names.
                'certified_payroll', 'prequalification', 'trade_licence', 'tax_registration', 'bond',
                'safety_plan', 'method_statement', 'training_matrix',
            ]);

            /*
             * §12: "because a lien waiver is per payment period and an insurance certificate is per policy period".
             * The scope decides which certificate a document has to exist for, and a period-scoped document that
             * covered everything would let one waiver clear every payment for the life of the contract.
             */
            $table->enum('scope', ['company', 'contract', 'period'])->default('company');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            // The waiver against *this* payment, which is what makes a period-scoped document checkable.
            $table->foreignId('covers_certificate_id')->nullable()
                ->constrained('construction_payment_certificates')->nullOnDelete();

            $table->string('reference')->nullable()->comment('Policy or certificate number');
            $table->string('issuer')->nullable()->comment('The insurer, the authority, the bank');
            $table->date('issued_on')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('expires_on')->nullable();
            $table->decimal('amount_covered', 15, 2)->nullable();

            // Received and verified are two different facts: a certificate in the inbox is not a certificate somebody
            // has read.
            $table->date('received_on')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();

            /*
             * The override trio. Waiving a *requirement* for one document is not the same as overriding a
             * certification — this is "we have accepted that this subcontractor will not produce a training matrix",
             * recorded so that the decision has an owner.
             */
            $table->unsignedBigInteger('waived_by')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->text('waiver_reason')->nullable();

            // The scan itself, in the ISO 19650 register (§15) rather than in a column here.
            $table->foreignId('document_id')->nullable()->constrained('construction_documents')->nullOnDelete();

            /*
             * Which expiry threshold has already been warned about, so the daily run only notifies when the answer
             * changes — copied from `employee_documents`, including the reasoning.
             */
            $table->smallInteger('expiry_notified_at_days')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'kind']);
            $table->index(['contract_id', 'kind']);
            $table->index('expires_on');
        });

        /*
         * §12: "which kinds are required, per contract or as a company-level template, and what each `blocks`
         * (`none|certification|payment|both`) with a `grace_days`".
         */
        Schema::create('construction_compliance_requirements', function (Blueprint $table) {
            $table->id();

            /*
             * Null means a **company-level template**: this is required of every subcontractor unless a contract says
             * otherwise. Without the template, every contract would restate the same six insurance requirements and
             * the seventh would be forgotten on the contract that mattered.
             */
            $table->foreignId('contract_id')->nullable()->constrained('construction_contracts')->cascadeOnDelete();

            $table->enum('kind', [
                'general_liability', 'workers_compensation', 'professional_indemnity', 'contract_works',
                'lien_waiver_conditional_progress', 'lien_waiver_unconditional_progress',
                'lien_waiver_conditional_final', 'lien_waiver_unconditional_final',
                'certified_payroll', 'prequalification', 'trade_licence', 'tax_registration', 'bond',
                'safety_plan', 'method_statement', 'training_matrix',
            ]);

            /*
             * **What it stops.** `certification` is the one §12 argues for: blocking at payment leaves an approved
             * payable finance cannot pay, which is worse than a refusal because the liability already exists and the
             * stuck payment has nobody's name on it.
             */
            $table->enum('blocks', ['none', 'certification', 'payment', 'both'])->default('certification');

            /*
             * Days past expiry that are still tolerated. A renewal in the post is ordinary, and a register that
             * stopped a certificate on the day a policy lapsed would be overridden every month until somebody set the
             * grace period they should have set at the start.
             */
            $table->unsignedSmallInteger('grace_days')->default(0);

            $table->decimal('minimum_cover', 15, 2)->nullable()
                ->comment('Where the contract specifies a sum insured, so a token policy does not satisfy it');

            $table->text('notes')->nullable();
            $table->timestamps();

            // One requirement per kind per contract — two would each have their own `blocks` and nobody would know
            // which applied.
            $table->unique(['contract_id', 'kind']);
        });

        /*
         * The override, recorded **on the certificate** rather than on the compliance row.
         *
         * §12: "the override is a permission plus a mandatory reason recorded on the certificate". That is the right
         * place because the decision is about *this payment* — certifying despite expired cover once is a judgement
         * about one month, and putting it on the compliance document would silently clear every later certificate too.
         */
        Schema::table('construction_payment_certificates', function (Blueprint $table) {
            $table->timestamp('compliance_override_at')->nullable();
            $table->unsignedBigInteger('compliance_override_by')->nullable();
            $table->text('compliance_override_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('construction_payment_certificates', function (Blueprint $table) {
            $table->dropColumn(['compliance_override_at', 'compliance_override_by', 'compliance_override_reason']);
        });

        Schema::dropIfExists('construction_compliance_requirements');
        Schema::dropIfExists('construction_compliance_documents');
    }
};
