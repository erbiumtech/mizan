<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress claims, payment certificates and their deductions — `docs/construction-management-plan.md` §10.
 *
 * **Two tables, because they are two documents.** What the contractor submits and what the certifier issues have
 * different parties, different dates and different legal effect: time bars run from the statement, the payment
 * period runs from the certificate. One row cannot hold two issue dates and two authors honestly. And on the
 * payable side we certify a *different* number from the one applied for — "applied versus certified is the single
 * figure every commercial manager asks for", visible only if both survive.
 *
 * AIA's G702 is the counter-argument, being literally one form serving as both. The answer: G702 is *printed* as
 * one form, and two tables print it as one form by joining. One table cannot print a FIDIC certificate that
 * certifies a figure different from the statement without inventing shadow columns.
 *
 * **Cumulative is stored; the period movement is derived.** Both an Interim Payment Certificate and a G703 are
 * cumulative documents — "total completed and stored **to date**". Store the period amount and sum for the
 * cumulative, and a corrected earlier certificate silently breaks every later total. Store the cumulative and a
 * correction is self-healing: the next certificate's "this period" figure absorbs it, which is exactly what
 * happens on paper.
 *
 * **Draft computes, issue freezes.** The house rule is that totals are computed, and it protects *running
 * balances*. A payment certificate is not a running balance; it is a statement of a moment handed to a third
 * party. `Invoice::exchangeRate()` already makes exactly this exception and says why — "a rate recorded later for
 * the invoice date must not silently restate an invoice that has already been issued".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_progress_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('construction_contracts')->cascadeOnDelete();
            $table->string('claim_number')->comment('STMT-7 under FIDIC, APP-7 under AIA');

            // The period the claim measures. `period_end` is the valuation date every figure is "to".
            $table->date('period_start')->nullable();
            $table->date('period_end');
            $table->date('submitted_on')->nullable();

            $table->enum('status', ['draft', 'submitted', 'under_review', 'certified', 'rejected', 'superseded'])
                ->default('draft');

            // What the contractor says it is worth, cumulative. The certifier's own figures live on the
            // certificate; these stay as submitted so "applied versus certified" survives.
            $table->decimal('claimed_work_to_date', 15, 2)->default(0);
            $table->decimal('claimed_materials_to_date', 15, 2)->default(0);
            $table->decimal('claimed_variations_to_date', 15, 2)->default(0);
            $table->decimal('claimed_gross_to_date', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamps();

            $table->unique(['contract_id', 'claim_number']);
            $table->index(['contract_id', 'period_end']);
        });

        /*
         * §10.2. **Value is authoritative; percent and quantity are inputs**, and `measurement_input` records
         * which one the person actually typed.
         *
         * Percent alone breaks the moment a variation grows a remeasured line's quantity: the stored percent is
         * against a stale denominator, so the line reads 87% while the money is fully certified. Quantity alone
         * has nothing to say about a lump-sum line. Recording the input is what lets a later re-derivation
         * reproduce the person's intent.
         */
        Schema::create('construction_progress_claim_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_claim_id')->constrained('construction_progress_claims')->cascadeOnDelete();
            $table->foreignId('contract_item_id')->constrained('construction_contract_items')->cascadeOnDelete();

            $table->enum('measurement_input', ['percent', 'quantity', 'value', 'milestone'])->default('percent');

            $table->decimal('cumulative_percent', 7, 4)->nullable();
            $table->decimal('cumulative_quantity', 14, 4)->nullable();
            // What everything resolves to, and what the certificate sums.
            $table->decimal('cumulative_work_value', 15, 2)->default(0);
            $table->decimal('cumulative_materials_value', 15, 2)->default(0);

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['progress_claim_id', 'contract_item_id'], 'cpcl_claim_item');
        });

        Schema::create('construction_payment_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('construction_contracts')->cascadeOnDelete();

            /*
             * **Nullable**, because FIDIC clause 14.6 permits the Engineer to certify without a conforming
             * statement. The nullability costs nothing; its absence would be a hard block on the FIDIC path.
             */
            $table->foreignId('progress_claim_id')->nullable()
                ->constrained('construction_progress_claims')->nullOnDelete();

            $table->string('certificate_number')->comment('IPC-7 under FIDIC, APP-7 under AIA');
            $table->unsignedInteger('sequence')->comment('7 — the number without its prefix, for ordering');

            $table->date('period_start')->nullable();
            $table->date('period_end')->comment('The valuation date every figure below is "to"');
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable()->comment('Issue plus the contract payment terms');

            $table->enum('status', ['draft', 'issued', 'paid', 'void'])->default('draft');

            /*
             * §8.4's five header figures, **frozen on issue**. The FIDIC certificate needed exactly these five
             * and a deductions child table; the AIA certificate needs the identical five. That is the whole of
             * the dual-standard claim.
             *
             * `contract_sum_to_date` is deliberately **absent**: it is `contract_sum_original +
             * variations_net_to_date`, both frozen here, so a stored third column could only ever disagree with
             * its own two inputs. §8.4's G702 mapping says line 3 is derived, and it is.
             */
            $table->decimal('contract_sum_original', 15, 2)->default(0);
            // **Agreed** variations only (§9). A provisionally priced variation is forecast, never certified.
            $table->decimal('variations_net_to_date', 15, 2)->default(0);
            $table->decimal('gross_work_to_date', 15, 2)->default(0);
            $table->decimal('gross_materials_to_date', 15, 2)->default(0);
            $table->decimal('gross_value_to_date', 15, 2)->default(0)->comment('Work plus materials — column G');

            // Kept as its own column as well as a deduction row: G702 line 5 prints the total retainage, and
            // the retention register reconciles against this figure (§11).
            $table->decimal('retention_to_date', 15, 2)->default(0);
            $table->decimal('previously_certified', 15, 2)->default(0);
            $table->decimal('current_due', 15, 2)->default(0);

            $table->unsignedBigInteger('certified_by')->nullable();
            $table->text('notes')->nullable();
            $table->text('void_reason')->nullable();

            /*
             * Guarded, never required (§18.1). Without Invoicing the *Raise invoice* action is absent and this
             * stays null — the certificate is still a contractual instrument that starts the payment period.
             * Also what prevents a second conversion, exactly as `quotation.invoice_id` does.
             */
            $table->unsignedBigInteger('invoice_id')->nullable();

            $table->timestamps();

            $table->unique(['contract_id', 'certificate_number'], 'payment_certificates_contract_number_unique');
            // Numbering per contract must have no gaps: a missing certificate number is a question at
            // adjudication (§8.3).
            $table->unique(['contract_id', 'sequence']);
            $table->index(['contract_id', 'status']);
        });

        /*
         * One row per contract item, with **`previous_*` frozen as a snapshot of the prior certificate** so
         * column D prints without joining to a certificate that may since have been voided (§10.2).
         */
        Schema::create('construction_certificate_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_certificate_id')->constrained('construction_payment_certificates')->cascadeOnDelete();
            $table->foreignId('contract_item_id')->constrained('construction_contract_items')->cascadeOnDelete();

            // Snapshotted from the contract item, so the printed schedule is reproducible years later even if
            // the line was superseded since.
            $table->string('item_no');
            $table->text('description');
            $table->decimal('scheduled_value', 15, 2)->default(0)->comment('Column C');

            $table->decimal('previous_work_value', 15, 2)->default(0)->comment('Column D — frozen snapshot');
            $table->decimal('previous_materials_value', 15, 2)->default(0);
            $table->decimal('cumulative_work_value', 15, 2)->default(0)->comment('D + E');
            $table->decimal('cumulative_materials_value', 15, 2)->default(0)->comment('Column F');

            // Per line, because retention does not apply to every line and G702 line 5a/5b splits it by basis.
            $table->decimal('line_retention', 15, 2)->default(0)->comment('Column I');

            $table->enum('measurement_input', ['percent', 'quantity', 'value', 'milestone'])->default('percent');
            $table->decimal('cumulative_percent', 7, 4)->nullable();
            $table->decimal('cumulative_quantity', 14, 4)->nullable();

            $table->timestamps();

            $table->unique(['payment_certificate_id', 'contract_item_id'], 'ccl_certificate_item');
        });

        /*
         * §10.3. **Every deduction is a row**, and the sign convention is stated once: a negative amount reduces
         * the payment.
         *
         * Generalising the bottom half of the certificate into one child table is what makes an NCR deduction
         * traceable to the NCR, advance recovery auditable against the contract terms, and liquidated damages a
         * first-class fact rather than a note in a memo field.
         */
        Schema::create('construction_certificate_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_certificate_id')
                ->constrained('construction_payment_certificates', 'id', 'certificate_deductions_certificate_fk')
                ->cascadeOnDelete();

            $table->enum('kind', [
                'retention', 'retention_release', 'advance_recovery', 'ncr', 'liquidated_damages',
                'back_charge', 'contra_charge', 'previous_certificates', 'tax_withheld',
                'unfixed_materials_adjustment', 'other',
            ]);

            $table->string('description');
            // **Signed.** Negative reduces the payment — one convention, stated once, here.
            $table->decimal('amount', 15, 2);

            /*
             * What it came from — the NCR, the back-charge, the retention movement. A **plain-column morph**, so
             * the type is written through `ModuleMap::alias()` in a mutator: §18.2 names this table among the
             * five `enforceMorphMap()` does not cover.
             */
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Carried through to the invoice line: retention is an asset, advance recovery a liability, an NCR
            // deduction is contract revenue (§10.4).
            $table->unsignedBigInteger('account_id')->nullable();

            // Whether the service computed it or a person added it. Both are ordinary; which it was is not.
            $table->boolean('is_automatic')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();

            $table->timestamps();

            $table->index(['payment_certificate_id', 'kind'], 'certificate_deductions_certificate_kind_index');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_certificate_deductions');
        Schema::dropIfExists('construction_certificate_lines');
        Schema::dropIfExists('construction_payment_certificates');
        Schema::dropIfExists('construction_progress_claim_lines');
        Schema::dropIfExists('construction_progress_claims');
    }
};
