<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Payslip table update
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['pay_period', 'version_id']);
            $table->string('month')->nullable()->after('employee_id');
            $table->foreignId('fiscal_year_id')->nullable()->after('month')->constrained('fiscal_years');
        });

    }

    public function down()
    {
        // Reverse karne ke liye wapis columns add karne parenge
    }
};
