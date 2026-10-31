<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three columns the work module adds to the timesheet — docs/project-management-plan.md §9.
 *
 * **This module's migration, not Timesheets'.** The columns arrive when the work module does and
 * Timesheets never learns their names: it reads `source` generically where it has to (a non-manual
 * row is read-only) and nothing else. That is what keeps `timesheets` sellable to a company that
 * runs no board, and `work` to one that runs no timesheet.
 *
 * The rule the pair of modules follows is in the plan's own words: *Clockify is the stopwatch, the
 * timesheet is the ledger, and an hour exists in the ledger exactly once.* `external_ref`, unique
 * per source, is what makes every sync idempotent — the same Clockify entry delivered three times
 * is one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table): void {
            // The item the time was logged against. Nullable: an hour on a project with no board is
            // still an hour on the project.
            $table->foreignId('work_item_id')->nullable()->after('project_id')
                ->constrained('work_items')->nullOnDelete();

            // Where the row came from: manual, clockify, smart_commit. A row that is not manual is
            // edited where it was made, never here.
            $table->string('source', 20)->default('manual')->after('description');

            // The Clockify entry id, the commit sha — whatever the source calls this row.
            $table->string('external_ref', 120)->nullable()->after('source');

            $table->index('work_item_id');
            $table->unique(['source', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table): void {
            $table->dropUnique(['source', 'external_ref']);
            $table->dropConstrainedForeignId('work_item_id');
            $table->dropColumn(['source', 'external_ref']);
        });
    }
};
