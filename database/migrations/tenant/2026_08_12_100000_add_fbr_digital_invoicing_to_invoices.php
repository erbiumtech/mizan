<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR digital invoicing: the reporting state of an invoice, and the log of every
 * attempt to report it.
 *
 * Reporting is a SECOND AXIS, not more values on `invoices.status`. Adding
 * `fbr_rejected` there would silently change the meaning of every existing
 * `whereIn('status', [ISSUED, PARTIALLY_PAID])` — InvoiceService::aging() does
 * exactly that, and an invoice FBR refused is still a real receivable that must
 * still age. Two questions, two columns.
 *
 * `fbr_status` defaults to `not_required`, so every invoice that already exists
 * — and every invoice at a company that is not integrated — reads as "nothing to
 * do" and behaves exactly as before. Nothing in this migration changes any
 * existing behaviour.
 *
 * See docs/fbr-digital-invoicing-plan.md §4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('fbr_status')->default('not_required')->after('status')->index();

            // What FBR gives back. The IRN is the reference a buyer or an auditor
            // verifies against, so it is indexed — "find the invoice for this IRN"
            // is the question a query from FBR's side starts with.
            $table->string('fbr_irn')->nullable()->after('fbr_status')->index();
            $table->string('fbr_usin')->nullable()->after('fbr_irn');

            // The load-bearing column. The 72-hour correction window runs from
            // here, so without it "can this still be cancelled" is unanswerable
            // and InvoiceService::void() cannot make the decision it now has to.
            $table->timestamp('fbr_reported_at')->nullable()->after('fbr_usin');

            // Stored rather than regenerated: a reprinted PDF of a reported
            // invoice must carry the same QR it carried when reported. Same
            // reasoning as recording the proration divisor on a payslip.
            $table->text('fbr_qr_payload')->nullable()->after('fbr_reported_at');
        });

        Schema::create('fbr_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt')->default(1);

            // Which integrator sent it. Multiple licensed integrators are
            // expressly permitted and a company may move between them, so an
            // attempt is only interpretable next to the driver that made it.
            $table->string('driver');

            // Unique, and that is the point. Queued submission + retries + an
            // integrator that timed out *after* recording the invoice is the
            // standard way to report the same sale twice — and a duplicate at
            // FBR is a correction that needs the Commissioner.
            $table->string('idempotency_key')->unique();

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();

            // accepted | rejected | error. `rejected` is FBR saying no;
            // `error` is never having heard back. They are not the same problem
            // and must not be summed into one count.
            $table->string('outcome');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fbr_submissions');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'fbr_status',
                'fbr_irn',
                'fbr_usin',
                'fbr_reported_at',
                'fbr_qr_payload',
            ]);
        });
    }
};
