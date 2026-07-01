<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            if (!Schema::hasColumn('payslips', 'withholding_tax')) {
                $table->decimal('withholding_tax', 10, 2)->default(0.00)->after('bonus');
            }

            if (!Schema::hasColumn('payslips', 'total_earnings')) {
                $table->decimal('total_earnings', 10, 2)->default(0.00);
            }
            if (!Schema::hasColumn('payslips', 'total_deductions')) {
                $table->decimal('total_deductions', 10, 2)->default(0.00);
            }
            if (!Schema::hasColumn('payslips', 'net_salary')) {
                $table->decimal('net_salary', 10, 2)->default(0.00);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn([
                'withholding_tax',
                'total_earnings',
                'total_deductions',
                'net_salary'
            ]);
        });
    }
};
