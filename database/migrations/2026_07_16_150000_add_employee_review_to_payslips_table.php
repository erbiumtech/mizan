<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->enum('employee_review', ['pending', 'accepted', 'rejected'])
                ->default('pending')
                ->after('pdf_path')
                ->comment('Employee acknowledgement; rejection is advisory only');
            $table->timestamp('employee_reviewed_at')->nullable()->after('employee_review');
            $table->string('employee_rejection_reason')->nullable()->after('employee_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['employee_review', 'employee_reviewed_at', 'employee_rejection_reason']);
        });
    }
};
