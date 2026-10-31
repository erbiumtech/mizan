<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat, and where an imported item came from — docs/project-management-plan.md §8.7 and §8.9.
 *
 * A channel subscription is one-way: a project posts a line per chosen event to a Slack channel or
 * a Teams incoming webhook. Not a provider in §8.1's sense — there is no repository to link and no
 * credential of the company's to hold beyond the webhook URL itself.
 *
 * `imported_from` keeps the source key of an item that arrived from Jira or Trello, so a search for
 * the old `PROJ-412` still finds it and running the import again corrects rather than duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_channel_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('kind', 10)->comment('slack|teams');
            // A Slack channel name, or a Teams incoming-webhook URL. Encrypted, because a Teams
            // webhook URL is the whole credential.
            $table->text('destination');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_posted_at')->nullable();
            $table->string('last_failure')->nullable();
            $table->timestamps();

            $table->index('project_id');
        });

        Schema::table('work_items', function (Blueprint $table): void {
            $table->string('imported_from', 80)->nullable()->after('archived_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', fn (Blueprint $table) => $table->dropColumn('imported_from'));
        Schema::dropIfExists('work_channel_subscriptions');
    }
};
