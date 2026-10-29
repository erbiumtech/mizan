<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily reports with verdicts, and badges — docs/saas-launch-plan.md §8.3, §8.4.
 *
 * A daily report is one per employee per date. The four `verdict_*` columns
 * are the model's and nothing else's; `manager_decision` is the person's, and
 * the ledger entry hangs off that. A recurring task gains a `kind` so the
 * daily report is a task of a reserved kind — missed and penalised by §8.2's
 * machinery, with no second schedule.
 *
 * A badge is earned once per employee: `employee_badges` is unique on the pair,
 * which is what makes "locked" mean "not yet".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_tasks', function (Blueprint $table): void {
            $table->string('kind', 20)->default('task')->after('title');
        });

        Schema::create('daily_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('report_date');
            $table->text('body');
            $table->dateTime('submitted_at');
            $table->string('verdict', 20)->nullable();
            $table->text('verdict_summary')->nullable();
            $table->json('verdict_questions')->nullable();
            $table->dateTime('verdict_at')->nullable();
            $table->string('verdict_model', 80)->nullable();
            $table->string('manager_decision', 20)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('manager_note')->nullable();
            $table->unsignedBigInteger('merit_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'report_date'], 'unique_daily_report');
            $table->index('report_date');
        });

        Schema::create('badges', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('icon', 60)->nullable();
            $table->unsignedSmallInteger('points')->default(0);
            $table->json('rule')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
        });

        Schema::create('employee_badges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('badge_id')->constrained('badges')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('awarded_on');
            $table->unsignedBigInteger('awarded_by')->nullable();
            $table->string('reason');
            $table->unsignedBigInteger('merit_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['badge_id', 'employee_id'], 'unique_employee_badge');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_badges');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('daily_reports');

        Schema::table('recurring_tasks', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
