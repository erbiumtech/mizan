<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Overtime requests, rest-day swaps, a break and a grace per pattern, holiday kinds — docs/saas-launch-plan.md §14.4. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_patterns', function (Blueprint $table): void {
            $table->unsignedSmallInteger('break_minutes')->default(0)->after('week_start');
            $table->unsignedSmallInteger('grace_minutes')->nullable()->after('break_minutes');
        });

        Schema::create('overtime_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('worked_on');
            $table->unsignedSmallInteger('minutes');
            $table->string('reason');
            $table->string('status', 12)->default('pending');
            $table->foreignId('submitted_by')->nullable()->index();
            $table->foreignId('decided_by')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'worked_on']);
        });

        Schema::create('rest_day_swaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_on');
            $table->date('rest_on');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['employee_id', 'work_on']);
            $table->unique(['employee_id', 'rest_on']);
        });

        Schema::table('holidays', function (Blueprint $table): void {
            $table->string('kind', 12)->default('public')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('rest_day_swaps');
        Schema::dropIfExists('overtime_requests');
        Schema::table('work_patterns', function (Blueprint $table): void {
            $table->dropColumn(['break_minutes', 'grace_minutes']);
        });
    }
};
