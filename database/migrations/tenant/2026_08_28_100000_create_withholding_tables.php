<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax withheld at source from suppliers — `docs/erpnext-gap-plan.md` Phase 4.
 *
 * The only withholding this application had was income tax on salary (§149), which `FbrTaxFile` files. A
 * company paying contractors and suppliers in Pakistan also withholds under **§153**, at a rate that depends
 * on the nature of the payment and on whether the payee is a filer, and files a §165 statement. Until now
 * that deduction had to be typed as a journal line and remembered.
 *
 * **Its own two tables, and that is a correction to the plan rather than a departure from it.** An earlier
 * draft said "withholding rows in `tax_rates`, no second tax table". Reading ERPNext's Tax Withholding
 * Category properly showed why that cannot work: `tax_rates` is name, code, rate, account and `is_default` —
 * no validity window, no thresholds, no party class. And **the thresholds are what §153 is**: a payment under
 * the limit is not withheld at all, and the limit is annual as well as per-payment. Putting that in
 * `tax_rates` means five new columns on a table eleven other things read, or a rate that is silently wrong
 * for every small payment.
 *
 * **Two rate columns rather than ERPNext's "withholding group".** Theirs is a general mechanism for
 * classifying parties; here the classification has exactly two members and is written into the statute, so a
 * filer rate and a non-filer rate beside each other read as the law reads. A third class would earn the
 * general mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholding_sections', function (Blueprint $table) {
            $table->id();

            /*
             * The section of the Ordinance, as the authority writes it: `153(1)(b)`.
             *
             * A string rather than an enum because the sections outlive any list this application ships, and
             * because it is what goes on the §165 statement — the same reason `tax_rates.code` exists.
             */
            $table->string('section');
            $table->string('label')->comment('What this covers, in the words somebody choosing it would use');

            /*
             * The two rates, which is the whole reason this is not a row in `tax_rates`.
             *
             * A non-filer pays more, and by design: the higher rate is the incentive to file. Stored as
             * percentages to two places — 8.00, 16.00 — because that is how the statute states them and how
             * anybody checking this against a circular will read them.
             */
            $table->decimal('rate_filer', 6, 3);
            $table->decimal('rate_non_filer', 6, 3);

            /*
             * Below these, nothing is withheld.
             *
             * `per_payment_threshold` is the single-transaction limit and `annual_threshold` the aggregate
             * for the tax year. Both nullable, because some sections have neither, and both are checked:
             * §153's services limit is annual, so a company paying 20,000 a month to one contractor crosses
             * it in the fifth month and not before. That arithmetic is `WithholdingService`'s.
             */
            $table->decimal('per_payment_threshold', 15, 2)->nullable();
            $table->decimal('annual_threshold', 15, 2)->nullable();

            /*
             * Where the withheld amount sits until it is paid to the authority.
             *
             * A liability of the company's: the money is the supplier's, held on the government's behalf.
             * Nullable so a section can be recorded before the chart has an account for it, and resolved at
             * deduction time — refusing to deduct is safer than posting to a guessed account.
             */
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            // Rates change by finance act, so a section is dated rather than edited. An open-ended `to` is
            // the current rate; superseding one is a new row rather than a rewrite of history.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'effective_from']);
        });

        Schema::create('withholding_deductions', function (Blueprint $table) {
            $table->id();

            /*
             * One row per deduction, which the §165 statement is assembled from.
             *
             * ERPNext keeps the same thing — a Tax Withholding Entries table beside its postings — and the
             * reason is worth stating: a journal line carries the *amount* and neither of the two figures the
             * statement needs, which are the taxable base and the rate applied. Deriving them backwards from
             * an amount and a rate that has since changed is arithmetic nobody should have to trust.
             */
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('withholding_section_id')->constrained('withholding_sections')->restrictOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('beneficiaries')->nullOnDelete();

            $table->decimal('taxable_amount', 15, 2)->comment('The gross payment the rate was applied to');
            $table->decimal('rate', 6, 3)->comment('The rate actually applied, snapshotted');
            $table->decimal('amount', 15, 2)->comment('What was withheld');

            /*
             * Filer or not, as it stood on the day.
             *
             * Snapshotted for the same reason the rate is: a supplier who files next year does not change
             * what was deducted last year, and the statement has to say what happened rather than what the
             * beneficiary record says today.
             */
            $table->boolean('was_filer');

            $table->date('deducted_on');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            // One deduction per payment: the payment is the event, and a second row against it would be a
            // second deduction of the same tax.
            $table->unique('payment_id');
            $table->index(['deducted_on']);
        });

        Schema::table('beneficiaries', function (Blueprint $table) {
            /*
             * Which section applies to what this supplier is paid for, and whether they file.
             *
             * On the beneficiary rather than on each payment because it is a fact about the supplier and the
             * work they do, and because a deduction that has to be chosen per payment is a deduction somebody
             * forgets — which is the whole failure this phase is about.
             *
             * **Null means no withholding**, so every beneficiary that exists today behaves exactly as it
             * does today. The plan's constraint is additive-only, and this column is what makes that true:
             * nothing is withheld until a company says which section applies to whom.
             */
            $table->foreignId('withholding_section_id')->nullable()->after('transaction_type_id')
                ->constrained('withholding_sections')->nullOnDelete();

            /*
             * Non-filer by default, and the default is a decision.
             *
             * The non-filer rate is the higher one. Over-deducting is recoverable by the supplier through
             * their own return; under-deducting is the company's own liability plus a penalty. So the safe
             * default is the one that costs somebody money they can get back rather than money the company
             * cannot.
             */
            $table->boolean('is_filer')->default(false)->after('withholding_section_id');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withholding_section_id');
            $table->dropColumn('is_filer');
        });

        Schema::dropIfExists('withholding_deductions');
        Schema::dropIfExists('withholding_sections');
    }
};
