<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the parallel personal ledger, and teaches the real one about tax.
 *
 * The personal_* tables existed because personal finance was designed as a
 * private per-user ledger inside a company's database. That design does not
 * survive the actual requirement: somebody keeping their own books wants an
 * accountant to do it for them and employs staff, which makes it an
 * organisation — and an organisation here is a tenant, with its own database,
 * its own roles and its own chart of accounts.
 *
 * So a personal account now uses `accounts` and `journal_entries` like any other
 * tenant, and everything that was duplicated goes. The one thing the real ledger
 * was missing is which Pakistani tax schedule an income account falls under,
 * which is added here.
 *
 * Written as a drop-if-exists rather than by editing the original migration,
 * because anybody who already ran it has the tables and needs them removed
 * rather than un-created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Which Pakistani schedule income booked here is taxed under. Only
            // meaningful on income accounts; null everywhere else, including on
            // every account of a business tenant. Tagging the account rather
            // than the entry means somebody classifies "Salary" once instead of
            // on every payment they record.
            $table->string('tax_regime')->nullable()->after('type');
        });

        Schema::dropIfExists('personal_entry_lines');
        Schema::dropIfExists('personal_entries');
        Schema::dropIfExists('personal_accounts');

        // The tax profile belongs to the account holder, not to whoever happens
        // to be signed in — an accountant looking at it is reading their
        // client's status, not their own. One row per tax year per tenant.
        if (Schema::hasColumn('personal_tax_profiles', 'user_id')) {
            Schema::table('personal_tax_profiles', function (Blueprint $table) {
                // Both indexes have to go before the column can: the original
                // gave user_id a plain index as well as the composite unique,
                // and SQLite refuses to drop a column an index still names.
                $table->dropUnique(['user_id', 'fiscal_year_id']);
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
                $table->unique('fiscal_year_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('tax_regime');
        });

        // The dropped ledger tables are not recreated: they held a design that
        // was replaced, and restoring empty copies of them would be pretending
        // the data could come back.
    }
};
