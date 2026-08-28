<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `invoices.kind` becomes a string — and this is a bug fix, not a Phase 5 convenience.
 *
 * The column was created as `enum('sale', 'purchase')` in July. The credit note added a third value in
 * August — `KIND_CREDIT_NOTE`, a model constant — and **widened nothing**, because the migration that
 * introduced it only added `credits_invoice_id` and `credit_reason`. `docs/erpnext-gap-plan.md` Phase 5 adds
 * a fourth, `debit_note`, and found the hole.
 *
 * **Why nothing noticed.** Laravel's SQLite grammar renders `enum` as a plain `varchar` with no check
 * constraint, and the tenant connection defaults to a SQLite file per company — so the whole test suite, and
 * every SQLite installation, stores `credit_note` happily. `.env.example` documents `TENANT_DB_DRIVER=mysql`
 * for production, and MySQL's ENUM is real: in strict mode the insert is rejected, and in non-strict mode it
 * is silently stored as the empty string. Either way credit notes have been broken on exactly the
 * configuration production runs, and the failure looks like "raising a credit note throws" or — worse — like
 * an invoice with no kind that every `where('kind', ...)` quietly omits.
 *
 * **A string rather than a wider enum**, which is the same choice `invoices.status` already made: the model's
 * constants are the authority, and a fifth kind should not need a migration to be storable. Widening the enum
 * would fix today's two values and leave the trap armed for the next one.
 *
 * **Run on every driver**, though only MySQL has anything to change: the stock-location migration already
 * widens `stock_movements.type` with the same `->change()` on whatever driver a tenant is on, and one code
 * path that the whole test suite exercises is easier to trust than a driver branch that is only ever taken in
 * production. On SQLite this rebuilds a table whose column is already a varchar — no gain, no loss, and
 * verified on every run.
 *
 * `recurring_invoices.kind` is left alone: a recurring agreement raises sales and bills, and neither note is
 * ever recurring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // 20 rather than the default 255: the longest value is `credit_note`, and the index on
            // ('kind', 'status') is read by every ageing query in the application.
            $table->string('kind', 20)->change();
        });
    }

    /**
     * Deliberately not reversible.
     *
     * Down would have to narrow the column back to two values, which means either refusing to run while any
     * credit or debit note exists, or deleting them. A migration whose rollback destroys documents a tax
     * inspector asks about should not offer one.
     */
    public function down(): void
    {
        //
    }
};
