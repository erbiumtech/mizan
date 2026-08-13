<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appraisals: cycles, goals, ratings and one-to-ones.
 *
 * Two decisions worth reading before adding to this.
 *
 * **Ratings do not touch pay.** No automatic increment, no formula from rating to salary.
 * A rating is an opinion; a package is a versioned EmployeeSetting somebody approves.
 * Wiring the first to the second would make the appraisal a payroll instruction, and the
 * first disagreement about a rating would become a payroll incident. The link is a
 * *suggested* new EmployeeSetting a human saves — docs/hrms-plan.md §4.5.
 *
 * **MPR is not duplicated.** This application already has a monthly self-report with
 * month-over-month comparison. A review cycle *reads* the MPRs in its period as evidence,
 * guarded on `modules()->enabled('mpr')`. That is the whole integration, and it is worth
 * more than another free-text box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('e.g. "2026 Annual" or "H1 2026"');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft')->comment('draft|open|calibrating|closed');
            $table->date('self_review_due_on')->nullable();
            $table->date('manager_review_due_on')->nullable();
            $table->text('calibration_notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_cycle_id')->constrained('review_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('reviewer_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('status')->default('pending')
                ->comment('pending|self_submitted|manager_submitted|shared|acknowledged');

            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->unsignedTinyInteger('final_rating')->nullable()->comment('After calibration. An opinion, and it reaches no payslip.');

            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();

            $table->timestamp('submitted_at')->nullable();

            // Distinct from submitted: a review written and not yet shared is a draft
            // about somebody, and they must not see it until a manager decides to share.
            $table->timestamp('shared_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();

            // One review per employee per cycle.
            $table->unique(['review_cycle_id', 'employee_id']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // Nullable: a goal may outlive the cycle it was set in, or be set outside
            // one entirely.
            $table->foreignId('review_cycle_id')->nullable()->constrained('review_cycles')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('metric')->nullable()->comment('How it is measured, in words');
            $table->string('target')->nullable();
            $table->string('actual')->nullable();
            $table->decimal('weight', 5, 2)->nullable()->comment('Percent, where a company weights goals');
            $table->string('status')->default('open')->comment('open|achieved|missed|dropped');
            $table->date('due_on')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('one_to_ones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('met_on');
            $table->text('notes')->nullable()->comment('Shared with the employee');

            // Manager-and-above ONLY, never downline-wide. EmployeeAccess grants a
            // manager their whole downline, and an employee must not read the private
            // notes about themselves — docs/hrms-plan.md §7.2 names this explicitly.
            $table->text('private_notes')->nullable()
                ->comment('Manager and above only. Never returned to the employee it is about.');

            $table->timestamps();

            $table->index(['employee_id', 'met_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_to_ones');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('review_cycles');
    }
};
