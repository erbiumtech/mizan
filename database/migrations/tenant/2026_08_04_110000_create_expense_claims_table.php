<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money an employee spent and is owed back.
 *
 * Today a reimbursement is a number typed into a payslip: no receipt, no approver,
 * no record of what it was for, and nothing to show anyone who asks why somebody
 * was paid 25,000 more in March. The destination already exists —
 * payslips.expense_reimbursement — so what this adds is the front door and the
 * paper trail behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // What it was for, in the same categories the ledger already uses, so a
            // claim and a company payment for the same thing land in the same place.
            $table->foreignId('transaction_type_id')->nullable()->constrained('transaction_types')->nullOnDelete();

            $table->date('claimed_on')->comment('When the money was spent, not when it was claimed');
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();

            // Per-company storage, streamed through TenantFileController — a receipt
            // is somebody's bank card and lunch, and it is not going on the web root.
            $table->string('receipt_path')->nullable();

            $table->enum('status', ['pending', 'approved', 'refused', 'settled'])->default('pending');

            // Soft references to landlord users, as EmployeeChangeRequest does: the
            // users table is not in this database.
            $table->foreignId('submitted_by')->index();
            $table->foreignId('decided_by')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->string('refusal_reason')->nullable();

            // How it was settled, and by what. A claim reimbursed with salary points
            // at the payslip that carried it; one paid on its own points at the
            // payment. Both nullable, and only one is ever set.
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'claimed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claims');
    }
};
