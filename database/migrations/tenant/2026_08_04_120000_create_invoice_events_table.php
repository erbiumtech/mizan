<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to an invoice, and when.
 *
 * The activity log records *changes* — which column went from what to what — and a
 * document's life is not a series of column changes. "When was this issued, when did
 * we last print it, when did they pay, and how much is still out?" is the first
 * conversation about a late invoice, and it was unanswerable.
 *
 * Only events the system actually witnesses are recorded. There is no "viewed":
 * that needs a client portal or a tracked email, and this application has neither by
 * decision. Inventing a column that never fills would be worse than its absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('event');
            $table->string('description');

            // Set for events that move money, so the history reads as a statement:
            // issued 11,800, paid 5,000, paid 6,800.
            $table->decimal('amount', 14, 2)->nullable();

            // Soft reference to a landlord user, as elsewhere in the tenant schema.
            $table->foreignId('caused_by')->nullable()->index();
            $table->timestamps();

            $table->index(['invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_events');
    }
};
