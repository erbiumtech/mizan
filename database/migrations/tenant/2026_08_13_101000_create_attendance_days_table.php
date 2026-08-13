<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per employee per day.
 *
 * **The only unbounded table in this plan.** 40 employees × 22 days ≈ 900 rows a
 * month, ~11k a year per tenant, and it grows with usage rather than with headcount.
 * So the model is `Prunable` from the first day with a retention setting, scheduled
 * the way ProjectEnvironmentCheck already is — not added later, when a five-year-old
 * tenant is already carrying 50k rows nobody reads.
 *
 * `not_marked` is deliberately a distinct status from `absent`, and it is the most
 * important thing in this file. **A month nobody filled in must not read as everybody
 * absent, because absent costs money.** It is the same distinction the environment
 * health checks make between `down` and `unknown`, and for the same reason: the
 * honest answer to "we do not know" is not the worst case.
 *
 * `leave_request_id` is what keeps this module and `leave` from disagreeing. An
 * approved leave writes `on_leave` days; an attendance row claiming `present` for a
 * date somebody is on approved leave is a contradiction the importer refuses rather
 * than overwrites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');

            $table->string('status')->default('not_marked')
                ->comment('present|absent|on_leave|holiday|weekly_off|half_day|work_from_home|not_marked');

            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();

            // Minutes rather than a decimal of hours: a clock produces minutes, and
            // rounding them into hours at storage time loses the arithmetic that the
            // overtime rate later multiplies.
            $table->unsignedSmallInteger('worked_minutes')->default(0);

            // Recorded from the first day and NOT reaching pay until phase 3a, which
            // is the honest state rather than a number that reaches pay by an
            // undefined route. `extra_work_hours` on the payslip is a *rupee amount*
            // despite its name, so minutes cannot simply be poured into it.
            $table->unsignedSmallInteger('overtime_minutes')->default(0);

            $table->unsignedSmallInteger('late_minutes')->default(0);

            $table->string('source')->default('manual')
                ->comment('manual|import|self_service|device — device is not built; the column anticipates it');

            $table->string('note')->nullable();

            // Set when this day is covered by approved leave, so the two modules
            // cannot contradict each other. Guarded: null at a company without
            // `leave`, and nullOnDelete because a withdrawn request gives the day back.
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->nullOnDelete();

            $table->timestamps();

            // Duplicate-safe, which is what makes a CSV re-import idempotent rather
            // than doubling a month. Biometric devices are out of scope, and this is
            // the key that lets one be added later without a data migration.
            $table->unique(['employee_id', 'date']);

            // The payroll-month read: every row for a month, which is what a
            // pro-rated payslip counts.
            $table->index(['date', 'status']);
        });

        Schema::create('attendance_regularizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');

            $table->string('requested_status');
            $table->dateTime('requested_check_in_at')->nullable();
            $table->dateTime('requested_check_out_at')->nullable();
            $table->text('reason');

            // ExpenseClaim's vocabulary again, verbatim. Four approval flows in this
            // codebase already disagree about whether it is `rejected` or `refused`;
            // this is the third module in a row that declines to add a fifth.
            $table->string('status')->default('pending')
                ->comment('pending|approved|refused');

            $table->foreignId('submitted_by')->nullable()->index();
            $table->foreignId('decided_by')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->text('refusal_reason')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_regularizations');
        Schema::dropIfExists('attendance_days');
    }
};
