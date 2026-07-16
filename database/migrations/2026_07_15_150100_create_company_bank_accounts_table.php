<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('title')->comment('E.g. SCB Main Salary Account');
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('account_no');
            $table->string('iban')->nullable();
            $table->foreignId('transaction_type_id')->nullable()->constrained('transaction_types')->nullOnDelete()
                ->comment('Purpose this account is earmarked for (Salary, Rent, Food…)');
            $table->boolean('is_default')->default(false)->comment('Default account for its transaction type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_bank_accounts');
    }
};
