<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kinds of leave a company grants, as rows.
 *
 * Reference data rather than an enum or a config array, for the reason
 * docs/hrms-plan.md §4.7 gives: a company that wants 18 annual days edits a row,
 * and a company that wants a "Study leave" adds one. Both are HR's job on a
 * Tuesday, not a deploy.
 *
 * The seeded set is a *default, not law*. Statutory minima in Pakistan are
 * provincial — Sindh, Punjab, KP and Balochistan each legislate their own
 * shops-and-establishments rules — so the day counts shipped are a starting point
 * a company's HR must confirm. This application already takes that position on
 * tax slabs and should not pretend to more certainty about labour law than it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();

            // The stable handle the code may refer to when it genuinely must —
            // compensatory accrual looks its type up by code. Unique because two
            // types with the same code makes that lookup depend on insertion order.
            $table->string('code')->unique()
                ->comment('Stable handle, e.g. "annual". Referred to by code where code must name a type');

            $table->string('label')->comment('As it appears to an employee, e.g. "Annual Leave"');

            $table->string('kind')->default('leave')
                ->comment('leave|maternity|paternity|bereavement|hajj|unpaid — groups types for reporting only');

            // The only flag on this table that reaches money, and it reaches it
            // via lop_days rather than directly. See docs/hrms-plan.md §11: a paid
            // day writes leaves_taken and costs nothing; an unpaid day writes
            // lop_days, which is the one column pro-rating may ever read.
            $table->boolean('is_paid')->default(true)
                ->comment('Paid leave costs the employee nothing; unpaid is the only kind that can reduce pay');

            $table->string('accrual_method')->default('annual_upfront')
                ->comment('annual_upfront|monthly_accrual|on_completion_of_service|compensatory|unlimited|none');

            // Nullable rather than 0 because "unlimited" and "none" have no annual
            // number at all, and 0 would read as an entitlement of nothing —
            // which is a different statement from "this type is not counted down".
            $table->decimal('days_per_year', 5, 1)->nullable()
                ->comment('Null for unlimited/none, which have no annual number');

            // The per-type cap on carry-forward, which is the second of the two
            // tiers in docs/hrms-plan.md §4.1: leave.carry_forward is the company
            // policy switch, this is the per-type limit. Annual carrying 5 days
            // while casual carries none is one company's normal and belongs in a
            // row rather than a second setting — a cap of 0 means this type never
            // carries even for a company that does.
            $table->decimal('max_carry_forward', 5, 1)->default(0)
                ->comment('Per-type cap; 0 means this type never carries even with the company switch on');

            $table->boolean('allows_half_day')->default(true);

            // Whether a medical certificate (or equivalent) is expected. The
            // attachment is visible to the approver and HR and to nobody else —
            // an approver is not a viewer, per docs/hrms-plan.md §7.
            $table->boolean('requires_document')->default(false);

            $table->unsignedSmallInteger('min_notice_days')->default(0)
                ->comment('Whether this blocks or merely warns is leave.min_notice_enforced');

            // Encashed on separation only. Year-end lapse pays nothing — two
            // different moments that docs/hrms-plan.md §4.1 is explicit about not
            // conflating.
            $table->boolean('is_encashable')->default(false)
                ->comment('Encashable on separation, never at the year-end lapse');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
