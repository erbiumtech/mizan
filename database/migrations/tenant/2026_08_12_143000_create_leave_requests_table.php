<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody asking to be away, and what was decided.
 *
 * The status vocabulary is ExpenseClaim's, verbatim — `pending / approved /
 * refused / cancelled` with `submitted_by`, `decided_by`, `decided_at` and
 * `refusal_reason`. Same columns, same names, same meaning. docs/hrms-plan.md §4.1
 * is explicit that a second vocabulary for the same idea is how one module ends up
 * saying `rejected` where another says `refused`, and §11 records that four
 * existing approval flows here already disagree that way. This one does not add a
 * fifth.
 *
 * `cancelled_at` / `cancelled_by` are the one addition ExpenseClaim has no need
 * for: a claim is for money already spent, and leave is for a future somebody's
 * plans change about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();

            $table->date('from_date');
            $table->date('to_date');

            // A projection of the leave_days rows written in the same transaction,
            // kept because every list and notification wants the number without a
            // join. It is NOT what a balance reads: the balance sums leave_days, so
            // this column drifting could never silently overpay somebody's
            // allowance. Recomputed whenever the days are.
            $table->decimal('days', 5, 1)->default(0)
                ->comment('Projection of leave_days for display; balances read leave_days, never this');

            $table->boolean('is_half_day')->default(false);

            $table->string('half_day_period')->nullable()
                ->comment('first|second — which half of the day, when is_half_day');

            $table->text('reason')->nullable();

            // Present when the type requires_document. Visible to the approver and
            // HR only: a manager approving leave needs the request and the balance,
            // not the medical certificate on it (docs/hrms-plan.md §7).
            $table->string('document_path')->nullable();

            $table->string('status')->default('pending')
                ->comment('pending|approved|refused|cancelled — ExpenseClaim\'s vocabulary, deliberately');

            // Soft landlord-user references throughout, the shape
            // invoice_events.caused_by uses: users live in the landlord database and
            // a constrained key would not resolve from a tenant connection.
            $table->foreignId('submitted_by')->nullable()->index();
            $table->foreignId('decided_by')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->text('refusal_reason')->nullable()
                ->comment('A refusal carries its reason — being told no without being told why is what approval exists to answer');

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->index();

            // The policy input that changed what leave_days were generated, stamped
            // so the generation is reconstructable.
            //
            // leave.sandwich_rule is read once, when the request is approved, and
            // never again — changing the policy must not restate leave somebody has
            // already taken. Without this column that promise is kept but
            // unauditable: two identical Friday-plus-Monday requests six months
            // apart consuming 2 and 4 days would look like a bug rather than a
            // policy change.
            $table->boolean('sandwich_rule_applied')->default(false)
                ->comment('Whether leave.sandwich_rule was on when these days were generated');

            $table->timestamps();

            // The two reads that matter: an approver's queue, and "what leave does
            // this employee have in this window".
            $table->index(['employee_id', 'status']);
            $table->index(['employee_id', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
