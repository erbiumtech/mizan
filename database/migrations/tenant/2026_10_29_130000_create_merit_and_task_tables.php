<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The merit-evidence ledger and recurring tasks — docs/saas-launch-plan.md §8.1, §8.2.
 *
 * `merit_entries` has no `updated_at` and the model refuses updates and deletes:
 * a correction is a new row of the opposite sign carrying `reverses_entry_id`.
 * The balance for a period is a SUM over the rows — computed, never stored.
 *
 * A task occurrence is unique per task, employee and due date, which is what
 * makes `tasks:roll` idempotent. Money (`penalty_amount`) is recorded on the task
 * and summed by `tasks:settle`; nothing here writes a payslip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on');
            $table->smallInteger('points');
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reason');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('reverses_entry_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_id', 'occurred_on']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('recurring_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('assignee_kind', 20)->default('all');
            $table->string('assignee_ref')->nullable();
            $table->json('assignee_rule')->nullable();
            $table->string('cadence', 10)->default('daily');
            $table->time('due_time')->default('17:00:00');
            $table->unsignedTinyInteger('due_weekday')->nullable();
            $table->unsignedTinyInteger('due_day_of_month')->nullable();
            $table->boolean('working_days_only')->default(true);
            $table->unsignedSmallInteger('grace_hours')->default(0);
            $table->unsignedSmallInteger('penalty_points')->default(0);
            $table->decimal('penalty_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('task_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recurring_task_id')->constrained('recurring_tasks')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('due_on');
            $table->dateTime('due_at');
            $table->dateTime('completed_at')->nullable();
            $table->string('completed_note')->nullable();
            $table->dateTime('missed_at')->nullable();
            $table->unsignedBigInteger('excused_by')->nullable();
            $table->dateTime('excused_at')->nullable();
            $table->string('excused_reason')->nullable();
            $table->unsignedBigInteger('merit_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['recurring_task_id', 'employee_id', 'due_on'], 'unique_task_occurrence');
            $table->index(['employee_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_occurrences');
        Schema::dropIfExists('recurring_tasks');
        Schema::dropIfExists('merit_entries');
    }
};
