<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Releases, and the column on an item that says which one it ships in — docs/project-management-plan.md §6.4.
 *
 * The second of the two columns the work-items migration left out on purpose, arriving with the
 * table it points at, exactly as `sprint_id` did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->date('planned_on')->nullable();

            /*
             * Stamped when somebody says it shipped, and the one fact the row exists to hold.
             *
             * A date rather than a state column: a release is either out or it is not, and "released"
             * with no date would be a release nobody could put in a changelog. Null is the state.
             */
            $table->date('released_on')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'released_on']);
        });

        Schema::table('work_items', function (Blueprint $table): void {
            // The *fix version*: which release this ships in. Nullable, because most of a backlog has
            // not been promised to one yet, and `nullOnDelete` because deleting a release un-promises
            // its items rather than deleting them.
            $table->foreignId('release_id')->nullable()->after('carried_over')
                ->constrained('work_releases')->nullOnDelete();

            $table->index('release_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('release_id');
        });

        Schema::dropIfExists('work_releases');
    }
};
