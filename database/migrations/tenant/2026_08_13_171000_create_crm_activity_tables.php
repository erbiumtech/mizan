<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: what happened, and what happens next.
 *
 * **`activities` and `next_actions` are deliberately separate tables.** A completed
 * activity is history and never changes; an action is a mutable intention with a due date,
 * an assignee and a snooze. Cramming both into one table gives every list query an
 * `is_done`-plus-`due`-plus-`occurred` filter that is wrong somewhere.
 *
 * **`next_actions` is the feature that makes the difference** between a CRM people use and
 * a data-entry chore. One rule, and it is the whole point: *an open opportunity with no
 * next action is surfaced as a problem.* Everything else in a CRM records the past; this is
 * the only part that changes what happens tomorrow.
 *
 * Both are polymorphic over Lead, Contact and Opportunity, so their `subject_type` needs
 * ModuleMap morph entries for the new models — `enforceMorphMap()` throws for anything
 * missing, which is the intended safety net.
 *
 * *Rejected:* reusing `comments` for activity logging. A comment is a discussion thread on
 * a record; a call at 14:20 that lasted nine minutes and ended in "send pricing" is a dated
 * event with a duration and an outcome. Comments stay available on the same records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();

            // Lead, Contact or Opportunity. Stored as the ModuleMap alias, never an FQCN.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->string('kind')->comment('call|meeting|email|whatsapp|note');
            $table->string('direction')->nullable()->comment('inbound|outbound — null for a note');

            $table->string('subject_line')->nullable();
            $table->text('body')->nullable();

            $table->timestamp('occurred_at');

            // What makes this an event rather than a comment.
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('outcome')->nullable()->comment('What came of it, e.g. "send pricing"');

            // Guarded on `employees`: who did it. Falls back to created_by without it.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->index();

            $table->timestamps();

            // §13 names this explicitly: activity volume is the one table that grows with
            // USAGE rather than with headcount, so the composite index is here from the
            // start rather than added when a timeline gets slow.
            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['employee_id', 'occurred_at']);
        });

        Schema::create('next_actions', function (Blueprint $table) {
            $table->id();

            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->string('title');

            // A date, and optionally a time. Most next actions are "call them Tuesday",
            // and forcing a time on that makes somebody invent one.
            $table->date('due_on');
            $table->dateTime('due_at')->nullable();

            $table->foreignId('assignee_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->index();

            // Pushed out without losing the original due date, so "this has been snoozed
            // four times" stays visible — which is usually the more useful fact.
            $table->date('snoozed_until')->nullable();

            $table->foreignId('created_by')->nullable()->index();
            $table->timestamps();

            // The two reads that matter: somebody's open list, and "which open deals have
            // no action at all" — the second being the whole point of the table.
            $table->index(['subject_type', 'subject_id', 'completed_at']);
            $table->index(['assignee_employee_id', 'completed_at', 'due_on']);
        });

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();

            // Phase 7. An employee rather than a user, so EmployeeAccess scoping applies.
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_amount', 15, 2);
            $table->string('currency_code', 3)->nullable();

            $table->string('kind')->default('won_value')
                ->comment('won_value|new_leads|activities — what the target counts');

            $table->timestamps();

            $table->unique(['employee_id', 'period_start', 'kind'], 'sales_targets_unique_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
        Schema::dropIfExists('next_actions');
        Schema::dropIfExists('activities');
    }
};
