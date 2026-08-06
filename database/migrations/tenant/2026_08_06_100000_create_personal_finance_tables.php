<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's own books: their chart of accounts, and a double-entry ledger over it.
 *
 * Deliberately separate tables from `accounts` / `journal_entries` rather than an
 * owner column on those, for two reasons that are properties of the existing
 * schema rather than preferences:
 *
 *  - `accounts.code` carries a global unique index, so two people could not both
 *    have a "5700 Rent". Here the uniqueness is per person.
 *  - `accounts.balance` is a cached scalar maintained by JournalEntryService::post().
 *    One scalar cannot hold a balance per owner, so there is no cached balance
 *    column here at all — personal balances are always summed from the lines,
 *    which is what the company's own report services already do.
 *
 * The separation also means a personal expense can never appear in the company
 * Trial Balance, P&L or Account Register, because those query the company tables
 * and these are not them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->index(); // soft ref -> landlord users

            $table->string('code');
            $table->string('name');

            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);

            // Which Pakistani schedule income booked here is taxed under. Only
            // meaningful on income accounts; null everywhere else. Tagging the
            // account rather than the entry means the person classifies "Salary"
            // once instead of on every payslip they record.
            $table->enum('tax_regime', ['salaried', 'business', 'rental', 'capital_gains'])
                ->nullable();

            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Per person, not globally — this is the whole reason the company
            // chart could not simply grow an owner column.
            $table->unique(['user_id', 'code']);
        });

        Schema::create('personal_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->index(); // soft ref -> landlord users

            $table->date('date');
            $table->string('description');

            // The tax year. July-June here already matches Pakistan's.
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years');

            $table->timestamps();

            $table->index(['user_id', 'date']);
        });

        Schema::create('personal_entry_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('personal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_account_id')->constrained();

            // One of the two is zero on any given line; the service refuses a line
            // that sets both or neither, and refuses an entry whose totals differ.
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);

            $table->timestamps();

            $table->index('personal_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_entry_lines');
        Schema::dropIfExists('personal_entries');
        Schema::dropIfExists('personal_accounts');
    }
};
