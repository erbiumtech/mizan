<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->morphs('payable'); // Employee or Beneficiary
            $table->foreignId('transaction_type_id')->constrained('transaction_types')->restrictOnDelete();
            $table->foreignId('company_bank_account_id')->nullable()->constrained('company_bank_accounts')->nullOnDelete()
                ->comment('Debit side; defaults to the type\'s default account');
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->cascadeOnDelete()
                ->comment('Set when generated from a payslip (salary payments)');
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();
            $table->string('details')->comment('Payment Details 1 in the bank file, e.g. "Office Rent July 2026"');
            $table->date('value_date')->nullable();
            $table->enum('payment_type', ['IBFT', 'BT', 'ACH', 'RTGS', 'LBC'])->nullable()
                ->comment('Explicit override; resolved per transaction when null');
            $table->enum('status', ['draft', 'approved', 'exported', 'paid'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'transaction_type_id']);
            $table->unique('payslip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
