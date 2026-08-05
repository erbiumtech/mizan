<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax as a rate rather than a number somebody types.
 *
 * `invoices.tax_amount` was a free decimal: tied to no rate, checked against
 * nothing, and impossible to report on — a tax summary cannot say what was charged
 * at 18% if 18% was never recorded, only its result. It also posted to one
 * hard-coded account, so two taxes could not be told apart in the ledger.
 *
 * Nothing about existing invoices changes here. Their tax_amount stays exactly as
 * entered and their lines carry no rate, which InvoiceService treats as the legacy
 * case it is: tax comes from the rates when a line names one, and from the typed
 * figure when none does. That is what lets a book with a year of invoices in it
 * start using rates on the next one rather than on all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('As it appears on the invoice, e.g. "GST 18%"');
            $table->string('code')->nullable()->comment('The authority\'s own code, for filing');

            // Percent, not fraction: 18.00 means 18%. Four decimals because some
            // jurisdictions levy fractions of a percent.
            $table->decimal('rate', 8, 4);

            // Where the tax collected or paid is booked. Nullable so a rate can be
            // created before the chart has an account for it, and so the shipped
            // default (2150) still applies.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false)
                ->comment('Offered first on a new line');
            $table->timestamps();

            $table->index(['is_active', 'is_default']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Per document, not per rate: the same 18% is quoted inclusive by one
            // client and exclusive by another, and it is the document that says
            // which. False for every existing invoice, which is what they were.
            $table->boolean('tax_inclusive')->default(false)->after('tax_amount')
                ->comment('Line amounts already include their tax');
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('tax_rate_id')->nullable()->after('account_id')
                ->constrained('tax_rates')->nullOnDelete();

            // What this line's rate worked out to. Stored rather than recomputed on
            // read so an issued invoice keeps the figure it was issued with even if
            // the rate is later changed — a rate change must not rewrite history.
            $table->decimal('tax_amount', 14, 2)->default(0)->after('tax_rate_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_rate_id');
            $table->dropColumn('tax_amount');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('tax_inclusive');
        });

        Schema::dropIfExists('tax_rates');
    }
};
