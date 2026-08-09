<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A month of payroll as a thing that can be signed off.
 *
 * Payslips are independent rows today. There is nothing to approve, so nothing is
 * ever agreed; and there is nothing to lock, so a payslip can be edited after it has
 * been sent to the employee, paid to their bank and posted to the ledger. Only
 * `sent_at` hints that it should not be, and a hint is not a control.
 *
 * Locking freezes the figures, not the money: payments already raised still go out,
 * because stopping them is a different decision from agreeing what the month was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('month')->comment('Payroll month name, e.g. August');
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            $table->enum('status', ['open', 'locked'])->default('open');

            // Soft references to landlord users, as elsewhere in the tenant schema.
            $table->foreignId('locked_by')->nullable()->index();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->index();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason')->nullable()
                ->comment('Why a signed-off month was opened again — the thing an auditor asks');

            $table->text('notes')->nullable();
            $table->timestamps();

            // One run per payroll month.
            $table->unique(['month', 'fiscal_year_id'], 'unique_run_per_month');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->foreignId('payroll_run_id')->nullable()->after('fiscal_year_id')
                ->constrained('payroll_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_run_id');
        });

        Schema::dropIfExists('payroll_runs');
    }
};
