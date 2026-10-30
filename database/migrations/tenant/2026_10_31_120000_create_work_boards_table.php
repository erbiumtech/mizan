<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A board is a saved query with columns — docs/project-management-plan.md §5.1.
 *
 * Everything that makes one board differ from another is a column here: which items it shows, which
 * statuses each of its columns holds, what it groups into lanes, and how many cards a column will
 * take. The board *screen* reads this and draws it; it decides nothing itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_boards', function (Blueprint $table): void {
            $table->id();
            // Null is a board across projects — "My work" is one, and §5.5 is why it exists.
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('kind', 10)->default('kanban')->comment('kanban|scrum');

            /*
             * The columns, as `[{label, status_ids: []}]`.
             *
             * Two statuses may share a column — *In review* and *In QA* under "Review" — and a status
             * in no column is hidden from this board. The screen says so where it lists them, because
             * a column that silently swallows cards is the first thing a team blames the tool for.
             */
            $table->json('columns')->nullable();

            // `none|assignee|epic|priority` — §5.1. A group-by, nothing more.
            $table->string('swimlane', 20)->default('none');

            // `{"<column index>": 5}`. A limit, not a warning: over it, the column refuses the drop.
            $table->json('wip_limits')->nullable();

            // The same shape the list's filters produce, so a board is a filter somebody saved.
            $table->json('filters')->nullable();

            // Subtasks are shown under their parent unless a board asks for them as cards of their own.
            $table->boolean('shows_subtasks')->default(false);

            $table->timestamps();

            $table->index(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_boards');
    }
};
