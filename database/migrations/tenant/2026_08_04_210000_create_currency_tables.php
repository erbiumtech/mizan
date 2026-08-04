<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money in more than one currency, in the ledger rather than only on a quote.
 *
 * The design decision this whole feature rests on: `debit_amount` and `credit_amount`
 * stay the *base* currency, always. Every report — trial balance, profit and loss,
 * balance sheet, cash flow, the registers — reads those two columns, and they keep
 * reading them unchanged. What is new is the foreign amount alongside, and the rate it
 * was converted at.
 *
 * The alternative — making the amount columns mean whichever currency the account is
 * in — would put a mixture of currencies in every existing SUM() in the codebase, and
 * every one of them would keep returning a number. That is the way this goes subtly
 * wrong, which is what the gap plan warned about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique()->comment('ISO 4217, e.g. PKR');
            $table->string('name');
            $table->string('symbol', 8)->nullable();
            $table->unsignedTinyInteger('decimals')->default(2);

            // Exactly one, and it is what the ledger is kept in. Changing it after
            // anything is posted is not a setting change, it is a re-statement of the
            // whole book, so nothing here offers to do it.
            $table->boolean('is_base')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code', 3);
            $table->date('effective_on');

            // Base units per one unit of this currency: 304 means EUR 1 = PKR 304.
            $table->decimal('rate', 18, 8);

            $table->string('source')->nullable()->comment('Where it came from — a bank advice, an agreement');
            $table->timestamps();

            // One rate per currency per day. A day with two rates is a question nobody
            // can answer later.
            $table->unique(['currency_code', 'effective_on'], 'unique_rate_per_day');
            $table->index(['currency_code', 'effective_on'], 'rate_lookup');
        });

        Schema::table('accounts', function (Blueprint $table) {
            // Null means the base currency. A foreign-currency account holds that
            // currency: its foreign balance is the real one and its base balance is a
            // translation that moves when rates move.
            $table->string('currency_code', 3)->nullable()->after('normal_balance');
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('credit_amount')
                ->comment('Null means the line is in the base currency');

            $table->decimal('foreign_debit_amount', 18, 4)->nullable()->after('currency_code');
            $table->decimal('foreign_credit_amount', 18, 4)->nullable()->after('foreign_debit_amount');

            $table->decimal('rate', 18, 8)->nullable()->after('foreign_credit_amount')
                ->comment('The rate the base amount was converted at, kept so a posted line can always explain itself');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'foreign_debit_amount', 'foreign_credit_amount', 'rate']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });

        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
