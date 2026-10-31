<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stopwatch's side of the mapping — docs/project-management-plan.md §8.8.
 *
 * One row per thing on each side: a Clockify *project* is linked to a project here, a Clockify
 * *task* is a work item. The workspace is a connection of kind `time` and needs no table of its
 * own; the user is an identity (§8.5); the entry is a timesheet row with `source = clockify` and
 * `external_ref` the entry id, which Phase 5 already built the ledger to hold.
 *
 * Nothing here names Clockify below the comment: Toggl Track is the same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_time_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('external_project_id', 120);
            $table->string('name', 200);
            $table->timestamps();

            $table->unique(['integration_connection_id', 'external_project_id']);
            $table->index('project_id');
        });

        Schema::create('work_time_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('work_time_project_id')->constrained('work_time_projects')->cascadeOnDelete();
            $table->string('external_task_id', 120);
            $table->string('name', 250);
            $table->timestamps();

            $table->unique(['work_time_project_id', 'external_task_id']);
            $table->unique(['work_item_id', 'work_time_project_id']);
        });

        /*
         * The project's own say on the stopwatch — §8.8's last paragraph.
         *
         * `work_time_source` overrides the company's `work.time_source` (here | clockify | either);
         * `work_task_on_progress` says whether the Clockify task is made when the item enters
         * in-progress rather than on the first timer. Both nullable: null defers to the company.
         */
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('work_time_source', 10)->nullable()->after('work_merge_moves_to_done');
            $table->boolean('work_task_on_progress')->nullable()->after('work_time_source');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['work_time_source', 'work_task_on_progress']));

        Schema::dropIfExists('work_time_tasks');
        Schema::dropIfExists('work_time_projects');
    }
};
