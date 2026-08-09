<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('"Vehicle finance", "Office mortgage"');
            $table->string('lender')->nullable();

            // Three accounts, because a repayment is a three-sided entry: it
            // reduces what is owed, records the cost of borrowing, and takes the
            // money from somewhere.
            $table->foreignId('liability_account_id')->constrained('accounts')->restrictOnDelete()
                ->comment('The liability carrying the outstanding balance');
            $table->foreignId('interest_account_id')->constrained('accounts')->restrictOnDelete()
                ->comment('Expense account the interest is charged to');
            $table->foreignId('payment_account_id')->constrained('accounts')->restrictOnDelete()
                ->comment('Cash or bank the instalment leaves from');

            $table->decimal('principal', 15, 2);
            // Nominal annual rate as a percentage: 14.5 means 14.5% a year,
            // divided by twelve for the monthly rate. Four decimals because
            // KIBOR-linked rates are quoted to two and spreads to more.
            $table->decimal('annual_rate', 8, 4)->default(0);
            $table->unsignedSmallInteger('term_months');
            $table->date('starts_on')->comment('Date of the first instalment');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_instalments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_on');

            // The whole schedule is stored rather than recomputed on the fly.
            // An amortisation table is a promise about specific amounts on
            // specific dates; recomputing it would let a rounding change or an
            // edited rate silently rewrite what was already paid.
            $table->decimal('opening_balance', 15, 2);
            $table->decimal('payment', 15, 2);
            $table->decimal('interest', 15, 2);
            $table->decimal('principal', 15, 2);
            $table->decimal('closing_balance', 15, 2);

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete()
                ->comment('Set when this instalment has been recorded');
            $table->timestamps();

            $table->unique(['loan_id', 'number']);
            $table->index(['loan_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_instalments');
        Schema::dropIfExists('loans');
    }
};
