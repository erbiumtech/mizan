<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time against a project.
 *
 * **Deliberately thin, and deliberately NOT an attendance record.** Time on a project
 * is a billing and utilisation fact; time at work is an attendance fact. Forcing the
 * two to reconcile to the minute would make both unusable — an eight-hour day with six
 * hours booked is normal, not an error. They are compared in a report, never enforced
 * against each other. docs/hrms-plan.md §4.3.
 *
 * `project_employee` already carries dated stints with `allocation_pct`, so the
 * plan-versus-actual comparison comes free: allocation says 50%, timesheets say 20%.
 *
 * The prize is billing. MonthlyBillingService bills a client per employee at full
 * monthly cost; a time-and-materials client is billed hours × rate, which is a
 * different bill from the same data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->date('date');

            // Minutes, like attendance, because a rate multiplied by a rounded decimal
            // of hours accumulates error across a month of entries.
            $table->unsignedSmallInteger('minutes');

            // The whole reason this table can bill. Non-billable time is still worth
            // recording — it is what utilisation is measured against — but it must
            // never reach an invoice.
            $table->boolean('is_billable')->default(true);

            $table->string('task')->nullable()->comment('Short label, e.g. "Migration" — grouped in reports');
            $table->text('description')->nullable();

            // Soft landlord-user reference, the invoice_events.caused_by shape.
            $table->foreignId('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();

            // Set when the entry has been billed. What stops an hour being invoiced
            // twice, and what stops somebody editing time a client has already paid
            // for — the same principle as a locked payroll run.
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            // The three reads: a person's week, a project's month, and what is billable
            // and not yet billed.
            $table->index(['employee_id', 'date']);
            $table->index(['project_id', 'date']);
            $table->index(['is_billable', 'locked_at']);
        });

        Schema::table('projects', function (Blueprint $table) {
            // Which client the project is for.
            //
            // Conceptually Projects', added here because hours-billing is what first
            // needed it: a time-and-materials invoice has to know whose hours these
            // are, and billing the wrong client is the failure this prevents.
            //
            // Nullable and guarded on `invoicing`: an internal project has no client,
            // and a company that runs projects without invoicing never fills it in.
            // nullOnDelete rather than restrict — losing a contact should not take the
            // project's history with it.
            $table->foreignId('contact_id')->nullable()->after('code')
                ->constrained('contacts')->nullOnDelete();

            // The first rung of rate resolution: project rate, else employee rate, else
            // a company default. Nullable throughout, because a company billing by
            // headcount rather than hours never fills any of them in.
            $table->decimal('hourly_rate', 12, 2)->nullable()->after('name')
                ->comment('Billed hourly rate for this project. Overrides the employee rate.');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('hourly_rate', 12, 2)->nullable()->after('employment_type')
                ->comment('What this employee\'s time is billed at, when the project does not say');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
            $table->dropColumn('hourly_rate');
        });

        Schema::dropIfExists('timesheet_entries');
    }
};
