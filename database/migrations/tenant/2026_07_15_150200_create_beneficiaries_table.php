<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('account_no')->nullable();
            $table->string('iban')->nullable();
            $table->enum('id_type', ['CNIC', 'NTN'])->nullable();
            $table->string('id_number')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->foreignId('transaction_type_id')->nullable()->constrained('transaction_types')->nullOnDelete()
                ->comment('What we usually pay this beneficiary for');
            $table->enum('payment_type', ['IBFT', 'BT', 'ACH', 'RTGS', 'LBC'])->default('IBFT')
                ->comment('Default iPayments payment type for this beneficiary');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiaries');
    }
};
