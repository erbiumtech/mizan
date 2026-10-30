<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each company bank account names the ledger account its money is booked to.
 *
 * Until now a payment credited 1100 Cash/Bank whichever bank account it was drawn on, so a company
 * with two banks had one ledger balance for both and could reconcile neither statement against it.
 * The account is now a field on the bank account.
 *
 * Existing rows are backfilled with 1100, which is exactly what their payments have always been
 * credited to — nothing already posted changes meaning, and a company that splits its banks later
 * picks the account on each bank account's form. A chart without 1100 leaves the column empty, and
 * payments then fall back as they always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->after('iban')->constrained('accounts')->nullOnDelete();
        });

        $cashBank = DB::table('accounts')->where('code', '1100')->value('id');

        if ($cashBank !== null) {
            DB::table('company_bank_accounts')->whereNull('account_id')->update(['account_id' => $cashBank]);
        }
    }

    public function down(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
