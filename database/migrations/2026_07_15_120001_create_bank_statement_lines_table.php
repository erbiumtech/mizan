<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('amount', 15, 2)->comment('Signed: positive = money in, negative = money out');
            $table->foreignId('matched_line_id')->nullable()->constrained('journal_entry_lines')->nullOnDelete()
                ->comment('The reconciled ledger line, when matched');
            $table->enum('match_status', ['unmatched', 'auto_matched', 'manually_matched', 'excluded'])->default('unmatched');
            $table->timestamps();

            $table->index('match_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
