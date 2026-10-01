<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approved non-profit's exemption approval, recorded the way
 * `invoices.commissioner_approval_ref` records a sales-tax permission: the
 * reference and the date it was approved, printed on the return pack and relied
 * on only when present. Phase 5 scaffolding — see docs/legal-entity-types-plan.md
 * §5. Both nullable; they mean nothing for any entity but `non_profit`, and the
 * s.100C credit that uses them is not computed yet (§7 Q3, held for the advisor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('tax_exempt_ref')->nullable()->after('legal_entity');
            $table->date('tax_exempt_approved_on')->nullable()->after('tax_exempt_ref');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['tax_exempt_ref', 'tax_exempt_approved_on']);
        });
    }
};
