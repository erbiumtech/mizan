<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/2026_07_04_000000_update_salary_slabs_table.php
public function up()
{
    Schema::table('salary_slabs', function (Blueprint $table) {
        $table->dropColumn('fiscal_year_start'); // Purana column hata dein
        $table->foreignId('fiscal_year_id')->constrained('fiscal_years'); // Naya relation
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
