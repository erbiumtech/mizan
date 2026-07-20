<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_taxes', function (Blueprint $table) {
            $table->decimal('total_annual_income', 12, 2)->default(0)->after('fiscal_year_id');
        });
    }

    public function down(): void
    {
        Schema::table('annual_taxes', function (Blueprint $table) {
            $table->dropColumn('total_annual_income');
        });
    }
};
