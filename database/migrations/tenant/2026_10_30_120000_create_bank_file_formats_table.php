<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank-file designer — docs/saas-launch-plan.md §14.1. A format per company
 * bank account, or the default when the account is null; the iPayments writer
 * is the seeded one, and a designed one is header, row and trailer templates
 * over placeholders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_file_formats', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('kind', 12)->default('template');
            $table->foreignId('company_bank_account_id')->nullable()->constrained('company_bank_accounts')->cascadeOnDelete();
            $table->text('header_template')->nullable();
            $table->text('row_template')->nullable();
            $table->text('trailer_template')->nullable();
            $table->string('line_ending', 4)->default('crlf');
            $table->string('extension', 8)->default('csv');
            $table->timestamp('previewed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('company_bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_file_formats');
    }
};
