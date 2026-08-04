<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice that is raised every month without anybody typing it.
 *
 * The same shape as BeneficiarySubscription, deliberately: a standing agreement, and a
 * link from each document it produced back to the agreement and the month it covers,
 * with a unique key on the pair. That pattern is proved — the scheduler is a cron job,
 * and a rerun must not bill a client twice.
 *
 * The template is a draft invoice in all but name: lines with amounts, accounts and tax
 * rates. What it raises is an ordinary Invoice, so issuing, posting, ageing, payment
 * and the PDF are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->enum('kind', ['sale', 'purchase'])->default('sale');

            $table->string('description')->comment('What this agreement is, for the list — not the invoice memo');
            $table->string('memo')->nullable()->comment('Goes on each invoice raised');

            $table->unsignedTinyInteger('day_of_month')->default(1)
                ->comment('Invoice date within the month; clamped to the length of short months');
            $table->unsignedSmallInteger('due_days')->default(15)
                ->comment('Payment terms: due date is the invoice date plus this');

            $table->boolean('tax_inclusive')->default(false);

            $table->date('starts_on');
            $table->date('ends_on')->nullable()->comment('Last month billed; open-ended when null');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'starts_on']);
        });

        Schema::create('recurring_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_invoice_id')->constrained('recurring_invoices')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 14, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('recurring_invoice_id')->nullable()->after('fiscal_year_id')
                ->constrained('recurring_invoices')->nullOnDelete();

            $table->date('period')->nullable()->after('recurring_invoice_id')
                ->comment('First day of the month a recurring invoice covers');

            // One invoice per agreement per month, enforced rather than hoped for.
            $table->unique(['recurring_invoice_id', 'period'], 'unique_recurring_period');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('unique_recurring_period');
            $table->dropConstrainedForeignId('recurring_invoice_id');
            $table->dropColumn('period');
        });

        Schema::dropIfExists('recurring_invoice_lines');
        Schema::dropIfExists('recurring_invoices');
    }
};
