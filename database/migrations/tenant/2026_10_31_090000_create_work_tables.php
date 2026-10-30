<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work items, the workflows that move them, and what hangs off one —
 * docs/project-management-plan.md §3 and §4.
 *
 * `sprint_id` and `release_id` are **not** here. The plan lists them among the item's columns, and
 * they arrive with the tables they point at in Phases 3 and 4: a nullable column with no constraint
 * is a column nothing can enforce, and adding it early buys a migration that has to be amended
 * rather than one that does not exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The project's key and its sequence.
         *
         * The key is three to six capitals typed into commit messages, branch names and chat, and
         * the sequence is taken under a row lock so two people creating an item at once cannot both
         * get WRK-12. `next_item_number` rather than max(number)+1: a deleted item must not hand its
         * number to the next one, because the number is in somebody's git history by then.
         */
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('key', 6)->nullable()->unique()->after('code');
            $table->unsignedInteger('next_item_number')->default(1)->after('key');
        });

        Schema::create('work_workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        /*
         * A status belongs to a workflow, and to exactly one of three categories.
         *
         * The category is the whole engine (§4.2): every report, filter and column mapping reasons
         * about `todo`/`in_progress`/`done` and reads the name. That is why "In review" and "In QA"
         * can both be in progress without a line of configuration anywhere, and why a company may
         * invent any status it likes.
         */
        Schema::create('work_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_workflow_id')->constrained('work_workflows')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('category', 12)->comment('todo|in_progress|done');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('colour', 20)->default('gray');
            $table->timestamps();

            $table->unique(['work_workflow_id', 'name']);
            $table->index(['work_workflow_id', 'sort']);
        });

        /*
         * What a workflow refuses, as rows rather than a script — §4.3.
         *
         * Four kinds and no fifth: `only_to` (from X, only to these), `requires_resolution`,
         * `requires_assignee`, `assignee_or_manager_only`. Jira's conditions, validators and post
         * functions are a language, and a language is where a workflow tool goes to become
         * unmaintainable.
         */
        Schema::create('work_workflow_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_workflow_id')->constrained('work_workflows')->cascadeOnDelete();
            $table->string('kind', 32);
            // The status the rule is about: the one being left, for `only_to`; the one being
            // entered, for the other three.
            $table->foreignId('work_status_id')->nullable()->constrained('work_statuses')->cascadeOnDelete();
            // For `only_to`: the statuses that status may move to. Null for the others.
            $table->json('to_status_ids')->nullable();
            $table->timestamps();

            $table->index(['work_workflow_id', 'kind']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('work_workflow_id')->nullable()->after('next_item_number')
                ->constrained('work_workflows')->nullOnDelete();
        });

        Schema::create('work_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('number');
            // Stored rather than computed: it is searched, sorted, and joined against from commit
            // messages, and a computed key is a key you cannot put an index on.
            $table->string('key', 16);

            $table->string('type', 20)->default('task');
            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('work_status_id')->constrained('work_statuses')->restrictOnDelete();
            $table->string('priority', 20)->default('medium');

            // One assignee. Not many: a card with three faces on it is a card nobody owns.
            $table->foreignId('assignee_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            // A landlord user id, read by App\Support\RecordAudience. Who asked for it.
            $table->unsignedBigInteger('reporter_user_id')->nullable()->index();

            // One level each way: a Subtask's Story, a Story's Epic — enforced in the model.
            $table->foreignId('parent_id')->nullable()->constrained('work_items')->nullOnDelete();

            $table->decimal('estimate_points', 6, 1)->nullable();
            $table->unsignedInteger('estimate_minutes')->nullable();
            $table->date('due_on')->nullable();

            /*
             * Where the card sits, as a string between its two neighbours.
             *
             * Moving a card writes one row rather than renumbering everything below it, which is
             * the difference between a drag that is instant on a backlog of eight hundred and one
             * that is not. See App\Modules\Work\Support\Rank.
             */
            $table->string('rank', 64);

            /*
             * Stamped by the status engine, never edited by hand — §4.2.
             *
             * `started_at` the first time the item enters an in-progress category, `resolved_at`
             * when it enters done, cleared when it leaves. Cycle time and lead time are computed
             * from these two and nothing else.
             */
            $table->timestamp('started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 30)->nullable();

            // Archived, never deleted while anything references it: every link survives.
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->unique('key');
            $table->index(['project_id', 'rank']);
            $table->index(['project_id', 'work_status_id']);
            $table->index('assignee_employee_id');
            $table->index('archived_at');
        });

        Schema::create('work_labels', function (Blueprint $table): void {
            $table->id();
            // Null means company-wide: a label every project may use.
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('colour', 20)->default('gray');
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        // Tables rather than JSON columns on the item, because both are filtered on.
        Schema::create('work_item_labels', function (Blueprint $table): void {
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('work_label_id')->constrained('work_labels')->cascadeOnDelete();

            $table->primary(['work_item_id', 'work_label_id']);
        });

        Schema::create('work_item_watchers', function (Blueprint $table): void {
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            // A landlord user id. No foreign key for the same reason every other one here has none:
            // users do not live in this database.
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->nullable();

            $table->primary(['work_item_id', 'user_id']);
        });

        /*
         * Four columns, which is what a file needs.
         *
         * No media library is added: the repo has none, and one table is less than the package's
         * four. Served through the tenant-file access that gates every other upload, never by a
         * public URL.
         */
        Schema::create('work_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime', 120)->nullable();
            $table->timestamps();
        });

        /*
         * Stored once, with the forward kind — §3.5.
         *
         * The reverse is read rather than written, so a link cannot drift into saying two things:
         * "A blocks B" and "B is blocked by A" are one row, not two that can disagree.
         */
        Schema::create('work_item_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('to_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->string('kind', 20)->comment('blocks|relates_to|duplicates');
            $table->timestamps();

            $table->unique(['from_item_id', 'to_item_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_links');
        Schema::dropIfExists('work_attachments');
        Schema::dropIfExists('work_item_watchers');
        Schema::dropIfExists('work_item_labels');
        Schema::dropIfExists('work_labels');
        Schema::dropIfExists('work_items');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_workflow_id');
        });

        Schema::dropIfExists('work_workflow_rules');
        Schema::dropIfExists('work_statuses');
        Schema::dropIfExists('work_workflows');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['key', 'next_item_number']);
        });
    }
};
