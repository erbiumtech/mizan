<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_no')->unique()->comment('PCV-YYYY-NNNN');
            $table->date('date');
            $table->string('details');
            $table->decimal('amount', 12, 2);
            $table->foreignId('transaction_type_id')->constrained('transaction_types')->restrictOnDelete()
                ->comment('Analysis column: Cleaning, Office Supplies, Fuel…');
            $table->string('receipt_path')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index('date');
        });

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->boolean('is_petty_cash_custodian')->default(false)->after('payment_type');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn('is_petty_cash_custodian');
        });

        Schema::dropIfExists('petty_cash_vouchers');
    }
};
