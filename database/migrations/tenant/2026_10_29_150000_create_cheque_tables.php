<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cheque printing — docs/saas-launch-plan.md §8.6.
 *
 * A book is a numbered range per company bank account; a cheque is one number
 * from it, printed once and never reused (void and reprint each write a row);
 * a layout is where on the paper each field goes, in millimetres, per account
 * or the default when `company_bank_account_id` is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheque_books', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_bank_account_id')->constrained('company_bank_accounts')->cascadeOnDelete();
            $table->string('prefix', 10)->nullable();
            $table->unsignedInteger('first_no');
            $table->unsignedInteger('last_no');
            $table->unsignedInteger('next_no');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cheques', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cheque_book_id')->constrained('cheque_books')->cascadeOnDelete();
            $table->foreignId('company_bank_account_id')->constrained('company_bank_accounts')->cascadeOnDelete();
            $table->string('cheque_no', 20);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('payee_name');
            $table->decimal('amount', 14, 2);
            $table->date('cheque_date');
            $table->boolean('crossed')->default(true);
            $table->string('status', 10)->default('printed');
            $table->dateTime('printed_at')->nullable();
            $table->unsignedBigInteger('printed_by')->nullable();
            $table->dateTime('cleared_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_bank_account_id', 'cheque_no'], 'unique_cheque_number_per_account');
            $table->index(['payment_id', 'status']);
        });

        Schema::create('cheque_layouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_bank_account_id')->nullable()->constrained('company_bank_accounts')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('page_width_mm', 6, 1);
            $table->decimal('page_height_mm', 6, 1);
            $table->json('fields');
            $table->timestamps();

            $table->unique('company_bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheque_layouts');
        Schema::dropIfExists('cheques');
        Schema::dropIfExists('cheque_books');
    }
};
