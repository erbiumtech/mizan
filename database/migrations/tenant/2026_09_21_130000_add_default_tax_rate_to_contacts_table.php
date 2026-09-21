<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which tax rate this party's invoice lines start on — the lazy half of `docs/erpnext-gap-plan.md`
     * §4 item 9.
     *
     * That item describes ERPNext's three documents (Tax Category, Tax Rule, Item Tax Template) and the
     * plan's own condition for building them: *only if picking a rate per line ever becomes the annoyance
     * they were designed for*. It is an annoyance in exactly one shape here — an exporting company whose
     * foreign clients are zero-rated and whose local ones are not — and that shape is a column, not three
     * documents. The priority-ordered rule engine stays unbuilt, and §4 item 9 keeps its design for the day
     * somebody needs to select on address or item.
     *
     * Null means "use the company default", which is every existing contact and is what the line already
     * did. `restrictOnDelete` because a rate somebody's invoices default to is not a rate to delete quietly.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('default_tax_rate_id')->nullable()->after('credit_limit')
                ->constrained('tax_rates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_tax_rate_id');
        });
    }
};
