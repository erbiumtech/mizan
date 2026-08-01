<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A month's bill to the client.
 *
 * The invoice itself is an ordinary Invoice, so it posts to the ledger, ages,
 * takes payments and prints exactly like any other. This table only records what
 * the invoice was built from — which client, which payroll month, and the rate
 * used to quote the total in the client's currency — so the month can be rebuilt
 * and cannot be billed twice by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('month')->comment('Payroll month name, e.g. "July"');
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            // The books are kept in the functional currency; the client is quoted
            // in theirs at the rate agreed for the month. Storing the rate is what
            // makes a reprint match what was sent.
            $table->string('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 15, 6)->nullable()
                ->comment('Functional currency units per one unit of `currency`');

            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // One bill per client per payroll month. Building the same month again
            // rewrites its draft invoice rather than raising a second one.
            $table->unique(['contact_id', 'month', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_runs');
    }
};
