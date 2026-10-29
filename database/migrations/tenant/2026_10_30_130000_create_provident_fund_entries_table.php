<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provident-fund ledger — docs/saas-launch-plan.md §14.1: append-only per
 * employee, the merit ledger's shape for money. Contributions arrive when a run
 * is signed off; opening balances, profit and withdrawals are recorded by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provident_fund_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('kind', 16);
            $table->decimal('employee_amount', 14, 2)->default(0);
            $table->decimal('employer_amount', 14, 2)->default(0);
            $table->string('reference')->nullable();
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_id', 'entry_date']);
            $table->unique(['payslip_id', 'kind']);
        });

        Schema::table('final_settlements', function (Blueprint $table): void {
            $table->decimal('provident_fund_amount', 14, 2)->default(0)->after('gratuity_amount');
        });
    }

    public function down(): void
    {
        Schema::table('final_settlements', function (Blueprint $table): void {
            $table->dropColumn('provident_fund_amount');
        });

        Schema::dropIfExists('provident_fund_entries');
    }
};
