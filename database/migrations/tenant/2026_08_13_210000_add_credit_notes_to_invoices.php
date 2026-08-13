<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credit note — docs/fbr-digital-invoicing-plan.md §2, third row of the correction table.
 *
 * That table has three rows and, until now, only two answers:
 *
 *  - never reported → `void()` unchanged;
 *  - reported, within 72 hours → cancel at FBR first, then void locally;
 *  - reported, past 72 hours → **refuse the void, and issue a credit note instead.**
 *
 * `InvoiceService::assertFbrAllowsVoid()` has been telling people to do that third thing for
 * as long as it has existed, and the thing did not exist. The plan is blunt about it: *"A
 * credit note does not exist in this codebase. `Invoice` has `KIND_SALE` and `KIND_PURCHASE`
 * and nothing negative… it is a prerequisite, not a follow-up, because without it the 'past
 * 72 hours' row has no answer at all."* This is that prerequisite.
 *
 * **Why a third `kind` and not a negative sale invoice.** A negative sale was the cheaper
 * option and it fails in three places. `nextInvoiceNumber()` would number it INV-, so the
 * document a customer receives would claim to be an invoice for minus five thousand rupees.
 * `saleEntryLines()` puts the total in the debit slot, and `postSystemEntry()` filters out
 * legs that are not greater than zero — so a negative total would be dropped and the entry
 * would fail the balance check with nothing to point at. And FBR treats a credit note as its
 * own document type, so a company reporting one would have to lie about which. A separate
 * kind gets its own CN- series, its own mirrored posting, and its own FBR identity, and every
 * existing query keys on `KIND_SALE` explicitly rather than on "not purchase", so nothing
 * silently absorbs it.
 *
 * **Why no separate table.** Everything a credit note needs — lines, tax rates, a currency
 * and the rate it was fixed at, a journal entry, an FBR status, a PDF — is what an invoice
 * already is. A `credit_notes` table would be a second copy of all of it, and a second place
 * for the tax treatment to drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Which invoice this credits. Self-referencing, and nullable for the two
            // ordinary cases: every sale and purchase invoice ever raised, and a standalone
            // credit note issued against a customer's balance rather than one document.
            //
            // restrictOnDelete, unlike almost every other foreign key in this schema. The
            // usual choice here is nullOnDelete — losing a parent should not take the child's
            // history with it — but a credit note whose invoice has been deleted is a
            // reversal of nothing, and the reason it was raised is exactly what a tax
            // inspector asks about. Refusing the delete keeps the pair together.
            $table->foreignId('credits_invoice_id')->nullable()->after('kind')
                ->constrained('invoices')->restrictOnDelete()
                ->comment('The invoice this credit note reverses. Null on ordinary invoices.');

            // Its own column rather than folded into `memo`, because this is the one field a
            // credit note has that an invoice does not, and "why was this credited" has to be
            // answerable in one query rather than by reading prose. Required by the service
            // for a credit note; null everywhere else.
            $table->string('credit_reason')->nullable()->after('credits_invoice_id')
                ->comment('Why the credit was raised. Required on a credit note.');

            // How much of an invoice has already been credited is read on every attempt to
            // credit it again — the over-credit guard — and on every invoice screen.
            $table->index('credits_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['credits_invoice_id']);
            $table->dropConstrainedForeignId('credits_invoice_id');
            $table->dropColumn('credit_reason');
        });
    }
};
