<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice raised in a currency, and the rate it was raised at.
 *
 * The amounts on the invoice stay in the invoice's own currency: that is what the client
 * is billed, what the PDF says and what they will pay. The journal entry is in the base
 * currency, translated at the rate stored here — so the rate is not a convenience, it is
 * the fact that ties the document to the ledger, and it has to survive.
 *
 * Null currency means the base one, and existing invoices are exactly that. Nothing about
 * them changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('kind')
                ->comment('Null means the base currency');

            // Base units per one unit of the invoice currency, as at the invoice date.
            // Kept rather than looked up: a rate recorded later for that day must not
            // silently restate an issued invoice.
            $table->decimal('exchange_rate', 18, 8)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'exchange_rate']);
        });
    }
};
