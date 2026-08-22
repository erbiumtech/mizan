<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control accounts and the GL postings made against them — `docs/construction-management-plan.md` §4.1 and §4.2.
 *
 * **§4 is the price of §3.** The job-cost ledger exists because a cost entry carries a unit rate and not every job cost
 * is a general-ledger event; the price is that nothing forces the two ledgers to agree, and this is the first table of
 * the machinery that pays it. §3's own migration says so: "if §4 is ever descoped, this section should be descoped with
 * it — the pair only makes sense together."
 *
 * **`construction_control_accounts` is a table rather than a config list**, and §4.2 gives the reason in five words:
 * "so a company can name its own accounts and the report can name them back". A hard-coded `5020` is a reconciliation
 * that silently reads the wrong account in every tenant whose chart of accounts was built by somebody else.
 *
 * **`purpose` is an addition to §4.2's `kind`, and it is the column that makes posting possible at all.** `kind` says
 * what a row is *for the report* — which accounts are in scope of the cost total, the WIP position, the accruals. It
 * cannot say which account the posting service should *credit* when it absorbs labour burden, because two accounts of
 * kind `recovery` are indistinguishable by kind alone. `purpose` names the rule: `labour_burden`,
 * `plant_internal_hire`, `site_labour`, `grni`, `subcontract_accrual`, `overhead_allocation`, `wip_movement`.
 *
 * **`purpose` is unique, and the nullability is doing real work.** Both SQLite and MySQL allow many nulls in a unique
 * index, so the constraint reads exactly as intended: any number of accounts may be *in scope of the report* with no
 * posting rule attached, and **at most one account may serve each rule**. §4.1 requires a summary journal whose lines
 * are explicable; a service that found two candidate credit accounts and took the first would post half a company's
 * burden to one account and half to another depending on insertion order, and no report would ever say so.
 *
 * **`construction_gl_postings` is one row per posting run**, which is the "one thing to reverse" that §3.2 argues for
 * everywhere else in this ledger. It is deliberately *not* a `construction_cost_batches` row: a batch owns the entries
 * it created through `batch_id`, and these entries already belong to the labour run or allocation that created them.
 * Stealing `batch_id` would break the thing §3.2 built it for. §4.1's actual requirement — "any GL line explodes into
 * its constituents in one query" — is met by `construction_cost_entries.journal_entry_id`, which every contributing
 * entry carries, and this table is what says which run wrote them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_control_accounts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('account_id');

            /*
             * §4.2's eight kinds, verbatim. What each row is *for the report*: which accounts make up the GL cost
             * total, which hold WIP, which hold the accruals, and so on.
             */
            $table->enum('kind', [
                'cost', 'wip', 'accrual', 'retention', 'revenue', 'recovery',
                'contract_asset', 'contract_liability',
            ]);

            /*
             * Which posting rule this account serves, or null for report scope only. See the class docblock — this is
             * the column that lets `ConstructionGlPostingService` find *the* credit account rather than *a* candidate.
             */
            $table->string('purpose')->nullable();

            /*
             * For `kind = cost`, which cost type debits here.
             *
             * §4.1 asks for a summary journal "per period per (GL account × cost type)", so a company that keeps
             * labour, material, plant and subcontract cost in four accounts gets four lines rather than one — which is
             * the difference between a general ledger somebody can read and a single number a month.
             *
             * Nullable, because a company that keeps one work-in-progress cost account for everything is entitled to,
             * and a required cost type would force it to invent a distinction it does not draw.
             */
            $table->enum('cost_type', ['labour', 'material', 'plant', 'subcontract', 'other'])->nullable();

            $table->string('label')->nullable()->comment('What this account is called on the reconciliation report');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // One row per account per kind: the same account may legitimately be both a cost account and, for a
            // company that nets them, something else — but naming it twice for one kind is a duplicate that would
            // double it in the report's total.
            $table->unique(['account_id', 'kind']);
            // At most one account per posting rule. See the class docblock: this is the constraint, not a convention.
            $table->unique('purpose');
            $table->index(['kind', 'is_active']);
        });

        Schema::create('construction_gl_postings', function (Blueprint $table) {
            $table->id();

            // The cost month posted, as a date — §3.4's rule: "a month index cannot say which of three years' Marches
            // it means, and the job outlives the fiscal year."
            $table->date('period_start');

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            /*
             * What the run came to, recorded rather than recomputed.
             *
             * §3.4 makes the same argument for the period's control totals: "a reconciliation computed later from live
             * data cannot tell you what the figures were on the day somebody signed the certificate." An entry
             * reversed next month must not change what this run said it posted.
             */
            $table->unsignedInteger('entry_count')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('line_count')->default(0);

            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();

            // The run this one backs out. One column rather than a scan, for §3.2's reason.
            $table->foreignId('reverses_gl_posting_id')->nullable()
                ->constrained('construction_gl_postings')->nullOnDelete();
            $table->text('reason')->nullable()->comment('Why a posting was reversed — required by the service');

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['period_start', 'posted_at']);
        });

        /*
         * **Which credit a pending entry owes, written by whoever created it.**
         *
         * The posting service could infer this — burden is flagged, internal plant has a plant log behind it, and
         * anything else labour-shaped is site labour. It did, in the first draft, and the inference was wrong in
         * principle: **the code that writes a cost knows which account it owes, and the code that posts it should not
         * have to guess.** Every guess is a rule in two places, and §4.5's accruals make that concrete — a GRNI accrual
         * and a subcontract accrual are the same shape of row and owe different accounts, so no inference could tell
         * them apart at all.
         *
         * Nullable, and the inference is kept as a fallback for exactly one reason: every row already in a live tenant
         * was written before this column existed. New writers set it; the fallback covers history and is documented in
         * `ConstructionGlPostingService::purposeFor()` as covering history only.
         */
        Schema::table('construction_cost_entries', function (Blueprint $table) {
            $table->string('gl_purpose')->nullable()->after('gl_treatment')
                ->comment('§4.1: which control-account rule credits this entry');

            // The posting query: pending entries in a period, grouped by what they owe.
            $table->index(['posting_period', 'gl_treatment', 'gl_purpose'], 'cost_entries_posting_idx');
        });
    }

    public function down(): void
    {
        Schema::table('construction_cost_entries', function (Blueprint $table) {
            $table->dropIndex('cost_entries_posting_idx');
            $table->dropColumn('gl_purpose');
        });

        Schema::dropIfExists('construction_gl_postings');
        Schema::dropIfExists('construction_control_accounts');
    }
};
