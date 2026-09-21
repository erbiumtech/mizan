<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much a customer may owe before a new invoice is refused — `docs/erpnext-gap-plan.md` §4 item 5.
     *
     * Null is "no limit", which is every existing customer and is deliberately not zero: zero would be a
     * limit of nothing, and refuse every invoice to every customer the moment this deployed. The check
     * itself is in `InvoiceService::issue()`, and a role that may pass it holds `InvoiceOverrideCreditLimit`.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)->nullable()->after('payment_terms_days')
                ->comment('Base currency. Null = no limit');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('credit_limit');
        });
    }
};
