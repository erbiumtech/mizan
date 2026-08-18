<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The head contract and its item schedule — `docs/construction-management-plan.md` §8.
 *
 * **One table for the head contract and the subcontract**, separated by `side`. The arithmetic is mirrored:
 * a subcontract has a schedule, variations, applications, certificates, staged retention and a certificate
 * that becomes a *purchase* invoice instead of a sale invoice. Two tables would mean two variation tables,
 * two certificate tables and two retention ledgers — "and staged retention release is the fiddliest logic in
 * the suite, so a second copy of it will diverge". The precedent is `invoices.kind`, one table for
 * receivable and payable with the service branching only at the posting step.
 *
 * **Three neutral date columns, and §8 calls this the highest-leverage decision in the section.**
 * Taking-Over under FIDIC and Substantial Completion under AIA are the same real-world event; so are expiry
 * of the Defects Notification Period and the end of the correction period. One `practical_completion_date`
 * plus `defects_period_days` makes retention release one function with a branch instead of two
 * implementations that drift.
 *
 * **Expiry of the defects period is computed and never stored** — `practical_completion_date +
 * defects_period_days`. A stored expiry is a date that stops agreeing with the completion date somebody
 * corrected last week, and the disagreement is worth money.
 *
 * **`measurement_basis` is not derived from `contract_standard`.** AIA contracts are routinely unit-price and
 * FIDIC Yellow is lump sum; it is the measurement basis, never the standard, that decides whether quantities
 * are remeasured. Conflating them is a real error rather than a tidiness point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            /*
             * The only structural difference between the two halves of this module: we bill the employer, or
             * we pay a subcontractor. Everything else in §8–§11 is the same arithmetic.
             */
            $table->enum('side', ['receivable', 'payable'])->default('receivable');

            /*
             * Copied down from the job and **frozen on first certification** (§8.1). It drives the numbering
             * series, the retention release rule and the printed form of documents already issued — flipping
             * it in month fourteen would retroactively re-label certificates one to thirteen, re-print them
             * on a different form and change how much retention is releasable today, with no event recording
             * any of it. The same argument `quotations` makes for supersession.
             *
             * It lives here rather than only on the job so a FIDIC Red head contract can sit above bespoke
             * subcontracts, which a job-level-only setting cannot express at all.
             */
            $table->enum('contract_standard', ['fidic', 'aia', 'custom'])->default('fidic');
            // Drives printed clause references and nothing else.
            $table->enum('fidic_book', ['red', 'yellow', 'silver', 'green', 'gold', 'pink'])->nullable();

            $table->string('contract_number')->comment('C-2026-014, SC-014-03');
            // The employer on a receivable contract, the subcontractor on a payable one. Nullable until
            // award, like the job's own client.
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            // A subcontract names the head contract above it, which is what makes back-to-back retention
            // reportable (§12).
            $table->foreignId('parent_contract_id')->nullable()->constrained('construction_contracts')->nullOnDelete();

            $table->string('title');
            $table->text('scope_summary')->nullable();

            $table->enum('measurement_basis', ['lump_sum', 'remeasured', 'mixed', 'cost_plus', 'target_cost'])
                ->default('lump_sum');
            $table->string('classification_system')->nullable();

            $table->string('currency_code', 3)->nullable();
            $table->decimal('exchange_rate', 20, 10)->nullable();

            // Original. The revised sum is original plus **agreed** variations, computed — §9's rule that a
            // provisionally priced variation is excluded from the certified sum and included in the forecast.
            $table->decimal('contract_sum', 15, 2)->default(0);

            $table->decimal('retention_percent', 5, 2)->nullable();
            // The "limit of retention": retention stops accruing at this share of the contract sum.
            $table->decimal('retention_limit_percent', 5, 2)->nullable();
            $table->decimal('retention_limit_amount', 15, 2)->nullable();
            $table->enum('retention_release_rule', ['fidic_two_stage', 'aia_substantial', 'single_stage', 'custom'])
                ->default('fidic_two_stage');
            $table->decimal('retention_first_release_pct', 5, 2)->nullable();
            // Materials on site are conventionally retained at a different rate, or not at all.
            $table->decimal('materials_retention_percent', 5, 2)->nullable();

            $table->decimal('advance_payment_amount', 15, 2)->nullable();
            $table->decimal('advance_recovery_start_pct', 5, 2)->nullable();
            $table->decimal('advance_recovery_rate_pct', 5, 2)->nullable();

            $table->decimal('liquidated_damages_per_day', 15, 2)->nullable();
            $table->decimal('liquidated_damages_cap_pct', 5, 2)->nullable();

            /*
             * Statutory payment regimes are configuration, never code (§10.3). These two express FIDIC's 28
             * and 56 days, NEC4's much shorter periods, and the UK Construction Act's notice clocks. "A
             * hardcoded statute is wrong the day it is amended, and nobody notices."
             */
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->unsignedSmallInteger('certification_period_days')->nullable();
            // Below this, a certificate is not issued and the value rolls into the next one.
            $table->decimal('minimum_certificate_amount', 15, 2)->nullable();

            $table->date('contract_date')->nullable();
            $table->date('commencement_date')->nullable();
            $table->unsignedSmallInteger('time_for_completion_days')->nullable();
            $table->date('contract_completion_date')->nullable();
            // Moves only through an approved extension of time (§13), never by hand.
            $table->date('extended_completion_date')->nullable();
            // Taking-Over under FIDIC, Substantial Completion under AIA. One column, two labels — and the
            // trigger for the first retention release under both.
            $table->date('practical_completion_date')->nullable();
            $table->unsignedSmallInteger('defects_period_days')->nullable();
            $table->date('final_completion_date')->nullable();

            $table->enum('status', ['draft', 'executed', 'in_progress', 'completed', 'closed', 'terminated'])
                ->default('draft');

            $table->timestamps();

            // Numbering is per contract rather than per year (§8.3), so the number itself is unique within
            // the tenant and the certificate series restarts at one under each.
            $table->unique(['job_id', 'contract_number']);
            $table->index(['job_id', 'side']);
            $table->index('status');
        });

        /*
         * §8.2. **The SOV line and the BOQ item are the same row**, and "which fields differ by standard:
         * none". AIA fills `item_no`, `description` and `scheduled_value` and leaves unit, quantity and rate
         * null; FIDIC fills unit, quantity and rate and derives the scheduled value. The difference is which
         * columns are null, not which table you are in — which is the proof the dual-standard model holds
         * rather than a claim about it.
         */
        Schema::create('construction_contract_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('construction_contracts')->cascadeOnDelete();
            // BoQ sections and G703 subtotal groups.
            $table->foreignId('parent_id')->nullable()->constrained('construction_contract_items')->nullOnDelete();

            $table->string('item_no')->comment('The printed line number: 2.1.4 or 03 30 00');
            $table->unsignedInteger('sort')->default(0);

            // **The join to job cost.** Without these two the certified value and the cost that earned it
            // cannot be compared, which is the whole question the commercial team asks.
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->nullable()->constrained('construction_cost_codes')->nullOnDelete();
            $table->string('classification_code')->nullable();

            $table->text('description');
            $table->enum('item_type', [
                'measured', 'lump_sum', 'provisional_sum', 'prime_cost_sum', 'dayworks',
                'contingency', 'milestone', 'advance', 'adjustment',
            ])->default('measured');

            $table->string('unit', 16)->nullable();
            $table->decimal('quantity', 14, 4)->nullable();
            $table->decimal('rate', 14, 4)->nullable();

            /*
             * **Recomputed from quantity × rate while the contract is draft, and frozen at execution.** It is
             * the figure the client signed and it must not move when somebody edits a quantity on a
             * remeasured line — otherwise G703's "work completed from previous applications" can exceed its
             * "scheduled value" and the form becomes arithmetically impossible.
             */
            $table->decimal('scheduled_value', 15, 2)->default(0);

            $table->boolean('retention_applies')->default(true);
            // Whether materials on site may be claimed against this line.
            $table->boolean('materials_allowed')->default(false);

            // Set when §9's approval writes the line, which is how AIA prints change-order lines appended to
            // the G703 rather than folded into the original bill.
            $table->unsignedBigInteger('source_variation_id')->nullable();
            $table->foreignId('supersedes_item_id')->nullable()->constrained('construction_contract_items')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['contract_id', 'item_no']);
            $table->index(['contract_id', 'sort']);
            $table->index('cost_code_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_contract_items');
        Schema::dropIfExists('construction_contracts');
    }
};
