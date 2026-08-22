<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: quotes.
 *
 * **A quote never touches the ledger. Not one journal entry, not a pending one.** It is an
 * offer; nothing has happened. Ledger involvement begins when it converts to an invoice, and
 * InvoiceService already knows how to do that correctly. docs/crms-plan.md §4 calls this the
 * single most important sentence in its section, because a quote that accrues revenue is an
 * audit finding.
 *
 * **Versioning is by supersession, not by edit.** Revising a sent quote creates version 2
 * pointing at version 1 through `supersedes_id`, and the customer's copy of v1 stays
 * reproducible. A quote is a document somebody has in their inbox; editing it in place makes
 * this system disagree with that inbox.
 *
 * The line shape deliberately mirrors `invoice_lines`, so conversion is a **copy rather than
 * a translation** — the failure mode of a translation being a quote and an invoice that
 * disagree about tax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();

            // Either party, and at most one — the same rule opportunities follow, because a
            // quote goes to a prospect before it goes to a customer.
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            // Guarded on `crm`: a quote may come from a deal, and a company using Quotations
            // without the pipeline simply never fills this in.
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();

            $table->string('currency_code', 3)->nullable();
            $table->decimal('exchange_rate', 15, 6)->nullable();

            $table->date('issue_date');

            // Expires on a schedule: one transition, one notification, not a daily nag.
            $table->date('valid_until')->nullable();

            $table->string('status')->default('draft')
                ->comment('draft|sent|accepted|declined|expired|superseded');

            $table->unsignedSmallInteger('version')->default(1);

            // Version 2 points at version 1. The chain is what keeps the customer's copy of
            // every earlier version reproducible.
            $table->foreignId('supersedes_id')->nullable()->constrained('quotations')->nullOnDelete();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->text('terms')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason')->nullable();

            // What it became. Set by conversion, and the reason a quote cannot be converted
            // twice.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->index();
            $table->timestamps();

            $table->index('status');
            $table->index('valid_until');
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();

            // Guarded on `inventory`, exactly as invoice lines are.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('description');
            $table->decimal('quantity', 15, 2)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);

            // The same TaxRate rows invoices use, so a converted line carries the same tax
            // treatment rather than a recalculated one.
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();

            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['quotation_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};
