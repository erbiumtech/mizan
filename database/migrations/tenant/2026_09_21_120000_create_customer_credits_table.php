<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money a customer has paid that is not against an invoice — `docs/erpnext-gap-plan.md` §2.2, the part
     * that plan deferred until "somebody has the problem".
     *
     * A deposit on a fixed-price project, a retainer, or the remainder of a transfer that came to more than
     * the invoices it was meant to pay. Until now `recordBatchReceipt()` refused all three, because there
     * was nowhere in the schema to hold a balance that is not against an invoice — which is exactly what
     * §2.2 named as the ceiling.
     *
     * **This is the table it said would be needed, and nothing more.** It is not a second ledger: 2600
     * Customer Advances carries the money, this row carries *whose* it is and how much of it is left. The
     * plan's refusal of a Payment Ledger stands — applying a credit posts through `recordPayment()` with the
     * advances account in place of the bank, so there is still one settlement path and one set of FX rules.
     */
    public function up(): void
    {
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();

            $table->date('received_on');
            $table->decimal('amount', 15, 2)->comment('What was received and held, in the base currency');

            // Kept as a column rather than summed from applications on read: a customer's remaining credit
            // is asked for on every invoice row that offers to apply it, and a stored figure the service
            // maintains inside the same transaction cannot drift from the postings it is derived from.
            $table->decimal('applied_amount', 15, 2)->default(0)->comment('How much of it has since been put against invoices');

            $table->string('reference')->nullable()->comment('The bank reference the money arrived with');
            $table->string('notes')->nullable();

            // The receipt's own posting: debit bank, credit 2600. Nullable for the same reason every other
            // document's is — a row whose entry was reversed keeps its history.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['contact_id', 'received_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
    }
};
