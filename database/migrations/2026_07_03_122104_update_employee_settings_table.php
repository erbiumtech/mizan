<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('employee_settings', function (Blueprint $table) {
            $table->dropColumn('version_id');
            $table->string('month')->after('employee_id'); // E.g., 'January'
            $table->foreignId('fiscal_year_id')->after('month')->constrained('fiscal_years');
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
