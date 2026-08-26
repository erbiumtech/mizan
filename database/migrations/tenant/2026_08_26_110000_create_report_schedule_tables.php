<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled and emailed reports — `docs/reports-expansion-plan.md` Phase 8, items 1 and 4.
 *
 * > **What a schedule is.** `report_schedules`: the report — a coded report's key *or* a Phase 6 definition —
 * > its filter state as json (the same state the URL carries, so "the schedule" and "the link" are the same
 * > thing), a period rule, a format (PDF / CSV / both), a cron expression with a timezone, recipients,
 * > `is_active`, and the owner.
 *
 * **Two tables, and the second is the one that must exist.** A schedule is configuration and could live
 * anywhere; `report_deliveries` is what stops a report being emailed twice. Item 4 is explicit that this is
 * not optional — "a queued render that exceeds its timeout is retried by design, and without this the retry
 * emails the report a second time" — which is the same fault `payslips.sent_at` was added for after it
 * happened.
 *
 * **No `company_id` on either.** Both are on the tenant connection, where the company *is* the database —
 * the fourth time this phase-set has made that call, after Phases 4.5, 6.3 and 7. A column that can only
 * hold one value plus a scope that can only be true is not the same shape as `table_views`, it is that
 * shape's shadow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();

            /*
             * Whose access decides what the rows are — item 2, and the whole of this phase's security model.
             *
             * "**The render runs as the schedule's owner**, whose access decides what the rows are." An
             * emailed report leaves the application's authorization behind: nobody has to log in to read it,
             * so *somebody* has to be the person whose permissions produced it, and it has to be recorded.
             *
             * Not a foreign key: `users` is on the landlord connection and a constraint across connections is
             * not one — the same note `report_definitions` and `saved_report_views` carry.
             */
            $table->unsignedBigInteger('user_id')->index();

            /*
             * Which report. A coded report's catalogue key (`AgedReceivables`) or a Phase 6 definition's
             * (`custom-7`) — one column, because both are keys the Reports hub already routes on and
             * `ReportRenderers` already resolves. Two columns with a check constraint between them would be
             * the same fact stored twice.
             */
            $table->string('report_key');

            /*
             * The filters, as the URL carries them: `{"compare": "previous_year", "month": "January", …}`.
             *
             * Item 1's own words — "the same state the URL carries, so 'the schedule' and 'the link' are the
             * same thing". Which is what lets item 7's "link to the live report in the body" land on exactly
             * the report that was attached.
             */
            $table->json('state')->nullable();

            /*
             * The period rule, as a `RelativePeriod` key — item 3.
             *
             * Relative, never two dates, for the reason Phase 6.2 gave and this phase depends on: "the aged
             * receivables every Monday" has to resolve its own dates each Monday. The resolved span is also
             * the idempotency key below, so this column is the difference between one send a month and a
             * second one nobody asked for.
             */
            $table->string('period');

            // PDF, CSV, or both. A report large enough to be worth scheduling is usually read in one and
            // filed in the other.
            $table->string('format')->default('pdf');

            /*
             * When, and in whose day.
             *
             * A cron expression because the shapes people ask for — "every Monday", "the day after payroll",
             * "the 1st" — are exactly what cron expresses, and Laravel already ships the parser
             * (`dragonmantank/cron-expression`). The timezone is separate and required: "monthly on the 1st at
             * 07:00" for a company in Karachi is not the same instant as it is for the server, and getting
             * that wrong is a report that arrives a day early every other month.
             */
            $table->string('cron');
            $table->string('timezone')->default('UTC');

            /*
             * Who it goes to, as email addresses.
             *
             * Addresses rather than user ids, because item 2 requires the list to be **re-authorised at send
             * time**: an address is matched against this company's members when the report is sent, so
             * somebody who has left is refused then rather than having been fine when the schedule was
             * written. An address that matches nobody is an *external* recipient, which needs its own
             * permission — and that is a decision the resolver can make from this column alone.
             */
            $table->json('recipients');

            $table->boolean('is_active')->default(true);

            /*
             * Suspended, and why — item 2's last sentence.
             *
             * "A schedule whose owner loses access to the report is suspended, not silently rendered with
             * fewer rows." Separate from `is_active` on purpose: one is what somebody chose and the other is
             * what the application decided, and collapsing them would let a resume button quietly re-enable a
             * schedule whose owner still cannot read the report.
             */
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable();

            // What the list shows, and the only thing here that is a convenience rather than a rule: the
            // deliveries table is the authority on what has been sent.
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            // Every read is "the active ones, are any due" — a small table, scanned once every fifteen
            // minutes per company.
            $table->index(['is_active', 'suspended_at']);
        });

        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_schedule_id')->constrained('report_schedules')->cascadeOnDelete();

            /*
             * The resolved period, as a string — item 3 and item 4 meeting.
             *
             * "**One delivery per period, whatever the queue does.** `report_deliveries` with
             * `unique(schedule_id, period_key)`". The key is the *resolved* span rather than the rule, so a
             * daily month-to-date report has one key a day and a monthly one has one a month, and the unique
             * index means a retried render cannot become a second email either way.
             */
            $table->string('period_key');

            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('rendered_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            /*
             * Who it actually went to, and which of them were external — item 2's "recorded on every
             * delivery".
             *
             * The list is resolved per send, so it is not the schedule's column: a delivery that went to four
             * of five recipients because one had left is exactly the fact somebody needs six months later,
             * and the schedule cannot hold it.
             */
            $table->json('recipients')->nullable();

            $table->text('error')->nullable();

            $table->timestamps();

            $table->unique(['report_schedule_id', 'period_key'], 'report_deliveries_unique_period');

            // The log reads newest first, across schedules — item 8.
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('report_schedules');
    }
};
