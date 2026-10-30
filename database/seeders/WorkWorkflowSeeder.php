<?php

namespace Database\Seeders;

use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use Illuminate\Database\Seeder;

/**
 * The workflow a company starts with — docs/project-management-plan.md §4.1.
 *
 * Four statuses in three categories, which is the shape nearly every team draws on a whiteboard
 * before it has opened a tool: something to do, somebody doing it, somebody checking it, done.
 * *In review* is `in_progress` rather than a fourth category, and that is the decision §4.2 rests
 * on — a review is work in progress by any honest reading, and three categories are what every
 * report here depends on.
 *
 * Idempotent on the workflow's name: a company that has renamed its statuses keeps them.
 */
class WorkWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $workflow = WorkWorkflow::firstOrCreate(
            ['name' => 'Default'],
            [
                'description' => 'The workflow every project uses until somebody writes another.',
                'is_default' => true,
            ],
        );

        $statuses = [
            ['name' => 'To do', 'category' => WorkStatus::TODO, 'sort' => 10, 'colour' => 'gray'],
            ['name' => 'In progress', 'category' => WorkStatus::IN_PROGRESS, 'sort' => 20, 'colour' => 'info'],
            ['name' => 'In review', 'category' => WorkStatus::IN_PROGRESS, 'sort' => 30, 'colour' => 'warning'],
            ['name' => 'Done', 'category' => WorkStatus::DONE, 'sort' => 40, 'colour' => 'success'],
        ];

        foreach ($statuses as $status) {
            WorkStatus::firstOrCreate(
                ['work_workflow_id' => $workflow->getKey(), 'name' => $status['name']],
                $status,
            );
        }
    }
}
