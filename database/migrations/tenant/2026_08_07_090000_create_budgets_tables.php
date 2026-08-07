<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete()
                ->comment('A budget plans one fiscal year; its months come from that year\'s dates');
            $table->string('name');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)
                ->comment('Superseded drafts stay for comparison rather than being deleted');
            $table->timestamps();

            // Two budgets of the same name in one year is a mistake, not a
            // revision — a revision gets its own name ("2026 revised").
            $table->unique(['fiscal_year_id', 'name']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete()
                ->comment('Income or expense only: a plan for a balance-sheet account has no period to compare against');

            // The first day of the month being planned, rather than a 1-12 index.
            //
            // A month index needs a base to mean anything, and the base here is a
            // fiscal year that starts in July — so index 1 is July of one calendar
            // year and index 12 is June of the next. Every read would have to
            // redo that arithmetic, and every one of them could get it wrong in a
            // different way. A date is already unambiguous, sorts correctly, and
            // compares directly against journal_entries.entry_date.
            $table->date('period_start');

            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            // One plan per account per month. The annual figure on the form is a
            // convenience that writes these rows; it is not stored, so the yearly
            // total is always the sum of the months and the two cannot drift.
            $table->unique(['budget_id', 'account_id', 'period_start']);
            $table->index(['budget_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
