<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns the first review of the work module asked for.
 *
 * `work_repositories.unlinked_at`: unlinking a repository used to delete its row, and the three fact
 * tables cascade on it — so "commits, branches and pull requests already attached to items stay",
 * which the confirmation promised, was false. An unlinked repository is now marked and kept; it
 * delivers nothing and offers nothing, and the history it recorded is still history.
 *
 * `work_items.assigned_at`: the digest's "assigned since yesterday" read `updated_at`, which moves
 * on every edit, so an item assigned months ago reappeared every morning somebody touched its
 * title. The moment the assignee last changed is its own stamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_repositories', function (Blueprint $table): void {
            $table->timestamp('unlinked_at')->nullable()->after('linked_at');
        });

        Schema::table('work_items', function (Blueprint $table): void {
            $table->timestamp('assigned_at')->nullable()->after('assignee_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', fn (Blueprint $table) => $table->dropColumn('assigned_at'));
        Schema::table('work_repositories', fn (Blueprint $table) => $table->dropColumn('unlinked_at'));
    }
};
