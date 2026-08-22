<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: tickets.
 *
 * **SLA is measured, not enforced.** `first_responded_at` and `resolved_at` against the
 * category's minutes, with breaches reported. docs/crms-plan.md §5 is explicit about why: a
 * system that refuses to close a ticket because an SLA elapsed helps nobody — the elapsed
 * time is a fact about the past, and blocking the close makes the register wrong as well as
 * late.
 *
 * **Inbound email is out of scope.** Parsing a mailbox into tickets means an IMAP poller,
 * threading heuristics and bounce handling — a subsystem, not a feature, and this application
 * has no inbound mail path at all. Tickets are created in the panel, with `channel` recording
 * how the customer actually asked.
 *
 * `is_internal` on a reply is staff-only, and it matters **the day a portal exists** (§7).
 * The flag is here now precisely so that the portal decision, if it is ever taken, does not
 * require finding every reply and deciding retrospectively which were private.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('default_priority')->default('normal')->comment('low|normal|high|urgent');

            // The SLA, in minutes. Nullable: a category with no commitment is not a breach
            // waiting to happen, and zero would read as "instantly overdue".
            $table->unsignedInteger('sla_response_minutes')->nullable();
            $table->unsignedInteger('sla_resolution_minutes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();

            // Guarded on `invoicing`: a ticket is usually from a customer, but `support`
            // requires nothing, so a company without Invoicing records the name in `subject`
            // and leaves this null.
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('contact_person_id')->nullable()->constrained('contact_persons')->nullOnDelete();

            // Guarded on `projects`: which engagement it is about, where there is one.
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->nullOnDelete();

            $table->string('subject');
            $table->text('description')->nullable();

            $table->string('channel')->default('panel')
                ->comment('How the customer actually asked: phone|email|whatsapp|panel|in_person');

            $table->string('priority')->default('normal');
            $table->string('status')->default('new')
                ->comment('new|open|pending_customer|resolved|closed');

            $table->foreignId('assignee_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->timestamp('opened_at');

            // The two SLA clocks. Set once each and never reset — a reopened ticket keeps its
            // original first response, because that is what the customer experienced.
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Counted rather than inferred from status history: "this has been reopened three
            // times" is the useful figure, and it survives the ticket being closed again.
            $table->unsignedSmallInteger('reopened_count')->default(0);

            $table->unsignedTinyInteger('satisfaction_rating')->nullable()->comment('1-5, if asked');

            $table->foreignId('created_by')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index(['assignee_employee_id', 'status']);
            $table->index('opened_at');
        });

        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();

            $table->text('body');

            // **Staff-only.** §7 keeps this flag for the day a client portal exists: a
            // customer may see their tickets and their replies, and never an internal note.
            // §12.13 asserts that no customer-facing query returns these — a guard written in
            // advance of the surface it guards.
            $table->boolean('is_internal')->default(false);

            $table->foreignId('author_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            // For a reply recorded on the customer's behalf — a phone call written up — where
            // the author is not an employee at all.
            $table->string('author_name')->nullable();

            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_categories');
    }
};
