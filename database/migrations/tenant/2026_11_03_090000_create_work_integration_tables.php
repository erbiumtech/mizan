<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The repositories that talk to a board — docs/project-management-plan.md §8.1, §8.3, §8.4, §8.5.
 *
 * Seven tables, in three groups. The **connection** is a company's door to one provider account
 * and holds the credential; the **repository** links one of its repositories to one project; and
 * the **event** is one inbound delivery, kept whole so a failed one can be replayed. Then the
 * **identity**, which is who a provider username is here. And the three **facts** a repository
 * yields about an item: a commit, a branch, a pull request — each a row per (item, repository,
 * thing), so a commit naming two keys links to both.
 *
 * The provider is a string, never an enum: §8.1's registry is what says which providers exist,
 * and a module that registers a fourth must not need a migration here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30)->index();
            $table->string('kind', 10)->comment('code|time');
            $table->string('label', 120);

            /*
             * How this connection authenticates, and with what.
             *
             * `app` is a GitHub App installation (the credential is the installation id; the
             * App's private key lives in .env, never in a tenant row). `oauth` holds a refresh
             * token. `token` is a personal access token typed in, for the company whose policy
             * forbids installing apps. The credentials are encrypted with the same cast the
             * environment passwords use.
             */
            $table->string('auth_kind', 10)->default('token');
            $table->text('credentials')->nullable();
            $table->json('scopes')->nullable();

            $table->string('external_account_id', 120)->nullable();
            $table->string('external_account_name', 200)->nullable();
            // Self-hosted GitLab, and one day GitHub Enterprise — §8.2.
            $table->string('base_url')->nullable();

            $table->unsignedBigInteger('connected_by')->nullable()->index();
            $table->timestamp('connected_at')->nullable();

            /*
             * The circuit breaker — §8.6.
             *
             * Five consecutive failures pause the connection for ten minutes, and a paused
             * connection queues nothing inbound either; the provider's redelivery covers the gap.
             */
            $table->string('status', 15)->default('active')->comment('active|paused|disconnected');
            $table->timestamp('paused_until')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('last_failure')->nullable();

            $table->timestamp('last_event_at')->nullable();
            // What the provider signs its deliveries with. Generated here, registered there.
            $table->string('webhook_secret', 80);

            $table->timestamps();
        });

        Schema::create('work_repositories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('external_id', 120);
            $table->string('full_name', 200);
            $table->string('default_branch', 120)->default('main');
            $table->string('url')->nullable();
            // The hook's id on the provider's side, so unlinking can remove it.
            $table->string('webhook_id', 120)->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'external_id']);
            $table->index('project_id');
        });

        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained('integration_connections')->cascadeOnDelete();
            // The provider's own delivery id. Every provider redelivers, and this is what makes
            // the second delivery a no-op — §8.3.
            $table->string('provider_event_id', 120);
            $table->string('type', 60);
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();

            // Named explicitly: MySQL caps an identifier at 64 characters and Laravel's implicit
            // name for this one is over it. SQLite does not care, so the suite passes and the
            // deploy fails — see the comment in 2026_10_30_110000.
            $table->unique(['integration_connection_id', 'provider_event_id'], 'unique_event_per_connection');
            $table->index(['integration_connection_id', 'processed_at']);
        });

        Schema::create('integration_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->string('provider_username', 120);
            $table->string('provider_user_id', 120)->nullable();
            $table->string('email')->nullable();
            // A landlord user id — who this is here. Null is "unmatched", and §8.5 says what
            // waits on it.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('matched_how', 10)->nullable()->comment('email|manual|claimed');
            $table->timestamps();

            // Named explicitly: MySQL caps an identifier at 64 characters and Laravel's implicit
            // name for this one is over it. SQLite does not care, so the suite passes and the
            // deploy fails — see the comment in 2026_10_30_110000.
            $table->unique(['integration_connection_id', 'provider_username'], 'unique_identity_per_connection');
        });

        Schema::create('work_item_commits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('work_repository_id')->constrained('work_repositories')->cascadeOnDelete();
            $table->string('sha', 64);
            $table->string('message');
            $table->foreignId('integration_identity_id')->nullable()->constrained('integration_identities')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();

            $table->unique(['work_repository_id', 'sha', 'work_item_id']);
        });

        Schema::create('work_item_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('work_repository_id')->constrained('work_repositories')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('url')->nullable();
            // Marked rather than removed: the branch was part of how the item got done.
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['work_repository_id', 'name', 'work_item_id']);
        });

        Schema::create('work_item_pull_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('work_repository_id')->constrained('work_repositories')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->string('state', 10)->default('open')->comment('open|merged|closed');
            $table->foreignId('integration_identity_id')->nullable()->constrained('integration_identities')->nullOnDelete();
            $table->string('url')->nullable();
            $table->string('head_branch', 255)->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->string('checks_state', 10)->nullable()->comment('pending|success|failure');
            $table->timestamps();

            // Named explicitly: MySQL caps an identifier at 64 characters and Laravel's implicit
            // name for this one is over it. SQLite does not care, so the suite passes and the
            // deploy fails — see the comment in 2026_10_30_110000.
            $table->unique(['work_repository_id', 'number', 'work_item_id'], 'unique_pull_request_link');
        });

        // Which status a pull request being opened moves an item to — §8.4. Null means "the one
        // named In review, if any".
        Schema::table('work_workflows', function (Blueprint $table): void {
            $table->foreignId('review_status_id')->nullable()->after('is_default')
                ->constrained('work_statuses')->nullOnDelete();
        });

        // The project's own say on what a merge does — §8.4. Null defers to the company
        // default (`work.merge_moves_to_done`). Named rather than related, like `key`.
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('work_merge_moves_to_done')->nullable()->after('work_workflow_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('work_merge_moves_to_done'));
        Schema::table('work_workflows', fn (Blueprint $table) => $table->dropConstrainedForeignId('review_status_id'));

        Schema::dropIfExists('work_item_pull_requests');
        Schema::dropIfExists('work_item_branches');
        Schema::dropIfExists('work_item_commits');
        Schema::dropIfExists('integration_identities');
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('work_repositories');
        Schema::dropIfExists('integration_connections');
    }
};
