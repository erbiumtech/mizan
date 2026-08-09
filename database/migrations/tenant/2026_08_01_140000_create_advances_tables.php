<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advances made to employees, recovered in instalments from payroll.
 *
 * The payslip already had a flat `advances` deduction typed in each month, which
 * says what was taken but never what is left. These two tables carry the part
 * that was being tracked in a spreadsheet: what was lent, what has come back, and
 * what is still outstanding.
 *
 * Recovery is a row per payslip rather than a running total on the advance, so
 * the balance is derived from what was actually deducted. A stored counter drifts
 * the first time a payslip is corrected or deleted; a ledger cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('monthly_instalment', 15, 2);
            $table->date('started_on');
            $table->string('status')->default('active')->comment('active, settled, cancelled');
            $table->string('reference')->nullable()->comment('Cheque number, agreement reference');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('advance_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advance_id')->constrained()->cascadeOnDelete();

            // One recovery per payslip, enforced: payroll recalculates a payslip on
            // every save, and without this a correction would take the instalment
            // a second time.
            $table->foreignId('payslip_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('recovered_on');
            $table->string('note')->nullable()->comment('Set for a recovery entered by hand rather than through payroll');
            $table->timestamps();

            $table->unique(['advance_id', 'payslip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_recoveries');
        Schema::dropIfExists('advances');
    }
};
