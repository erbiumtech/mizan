<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the thing on this line is actually delivered — `docs/erpnext-gap-plan.md` §4 item 3's other half.
     *
     * Phase 5 built the deferral itself (`DeferralService`) and left "the generator from an invoice line"
     * until service dates existed on the line, because an annual licence billed in July is eleven months of
     * next year's revenue and nothing on the invoice said so. These two columns say so. Both nullable and
     * both ignored by everything except `InvoiceService::deferOverServicePeriod()`, so an invoice that
     * carries neither is issued, posted and paid exactly as before.
     */
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->date('service_from')->nullable()->after('tax_amount');
            $table->date('service_to')->nullable()->after('service_from');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['service_from', 'service_to']);
        });
    }
};
