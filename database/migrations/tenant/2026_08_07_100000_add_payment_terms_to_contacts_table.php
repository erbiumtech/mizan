<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Days from the invoice date to the due date. Null is "no terms
            // agreed", which is not the same as 0 — 0 is due on receipt, and a
            // contact nobody has set terms for should not be reported as
            // demanding payment the same day.
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('bank_id')
                ->comment('Net days. Null = none agreed, 0 = due on receipt');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('payment_terms_days');
        });
    }
};
