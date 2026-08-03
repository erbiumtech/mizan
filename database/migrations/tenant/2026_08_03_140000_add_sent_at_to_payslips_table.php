<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the payslip was sent to the employee.
     *
     * A payslip is calculated, corrected, and only then released — so being
     * *created* says nothing about whether the person it belongs to has seen it.
     * This is what makes sending deliberate and repeat sends visible: the send
     * action refuses a payslip that has already gone unless it is asked to send
     * again, which is the difference between a month's payroll going out once and
     * an employee getting four copies because a clerk fixed three rows.
     */
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('employee_review_recorded_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('sent_at');
        });
    }
};
