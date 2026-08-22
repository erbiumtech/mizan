<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A prospect: somebody who is not yet a customer.
 *
 * This table is the one architectural decision in docs/crms-plan.md, and §1 records
 * why it exists rather than reusing `contacts`. A prospect is not a contact you can
 * invoice. Adding a `prospect` value to `contacts.kind` would mean every invoicing
 * query having to exclude it from then on, and it would make CRM unsellable without
 * Invoicing *and* Accounting. Moving `Contact` to Core was the cleaner long-term
 * shape and was rejected for a specific, concrete reason: `ContactView` and friends
 * are seeded in the `Invoicing` permission group, `PermissionSeeder` matches on
 * `['name', 'group']`, and the `permissions` table has **no unique index** — so
 * re-grouping them silently inserts duplicate rows and leaves existing roles pointed
 * at the old ones. That is a landlord-wide data migration, and not one worth spending
 * to ship a CRM.
 *
 * So: `crm` owns leads and requires nothing. A lead is captured, worked, and either
 * converted or lost entirely inside this module. Conversion — the moment a prospect
 * becomes somebody you can invoice — creates a `Contact`, and that action is hidden
 * when `modules()->enabled('invoicing')` is false. The precedent for a guarded
 * coupling rather than a declared requirement is Invoicing → Projects, recorded in
 * ModuleBoundaryTest.
 *
 * `leads`, `contacts` and (later) `applicants` all hold a name, an email, a phone, a
 * source and notes, and each converts into something else. §13 decided deliberately
 * that they stay three tables: a lead is an organisation with a person attached, an
 * applicant is a person with no organisation, and a contact is a party the ledger can
 * bill. They share a shape, not a meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            // A lead is an organisation with a person attached, which is what makes
            // it a different thing from an applicant. Either may be blank — a name
            // scribbled at a trade show is still a lead — but not both, which the
            // model asserts rather than the schema, because "at least one of two
            // columns" is not something a column constraint expresses portably.
            $table->string('company_name')->nullable();
            $table->string('person_name')->nullable();
            $table->string('title')->nullable()->comment('The person\'s job title, e.g. "Head of Finance"');

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            // Separate from phone on purpose: in this market the number somebody
            // answers on WhatsApp is often not the number on their card, and
            // App\Support\WhatsApp already sends to one.
            $table->string('whatsapp')->nullable();

            $table->string('city')->nullable();

            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();

            // Ownership is an employee, not a user, so EmployeeAccess scoping applies
            // unchanged: a sales manager sees their downline's leads and no further,
            // by the same BFS every other employee-keyed resource uses.
            //
            // Guarded rather than required. With `employees` unlicensed this column is
            // never offered and stays null, and `created_by` below is what answers
            // "whose lead is this" — that is the fallback docs/crms-plan.md §3 asks
            // for. Deliberately NOT a second `owner_user_id`: two columns for one
            // concept would be two answers to the same question, and every report
            // would have to coalesce them.
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('status')->default('new')
                ->comment('new|working|qualified|converted|lost');

            // A salesperson's own read on how good this is. Deliberately not a
            // computed score: docs/crms-plan.md §10a fixes the position that a score
            // is displayed as a suggestion and never stored as a fact, because an
            // unlabelled number in a pipeline table becomes a decision the moment
            // somebody sorts by it.
            $table->string('rating')->nullable()->comment('hot|warm|cold — a person\'s judgement, never a computed score');

            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->string('currency_code', 3)->nullable()
                ->comment('Soft reference to currencies.code; null means the company\'s base currency');

            $table->text('notes')->nullable();

            // The trail from prospect to customer, kept after conversion rather than
            // consumed by it: "where did this customer come from" is a lead-source
            // question asked years later, and it is the reason win/loss by source can
            // be reported at all.
            //
            // A real foreign key, even though the coupling is guarded at the
            // application level: licensing decides what is *offered*, never what is
            // migrated, so every tenant has a `contacts` table whether or not it
            // bought Invoicing. Referential integrity therefore costs nothing here.
            $table->foreignId('converted_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            // Free text at this phase, and knowingly so. `lost_reasons` is a table in
            // the plan (§3) because win/loss by reason is worth more than the
            // forecast — but that report is phase 4, and shipping the table now with
            // nothing reading it would be the column-that-never-fills the
            // invoice_events migration refused. Phase 4 adds lost_reason_id beside
            // this and backfills.
            $table->string('lost_reason')->nullable();
            $table->timestamp('lost_at')->nullable();

            // Soft landlord-user reference, the shape invoice_events.caused_by uses.
            // With `employees` off this is what ownership falls back to.
            $table->foreignId('created_by')->nullable()->index();

            $table->timestamps();

            // An owner's working list, and the status board.
            $table->index(['status', 'owner_employee_id']);

            // Deduplication reads. Phase 3.5 adds the lead-capture endpoint and the
            // dedup that must ship with it — matching on email, phone and company
            // name — and these are the indexes it will match on. They are here now
            // because they also serve the search a person does before typing a lead
            // somebody else already entered, which is the same query by hand.
            $table->index('email');
            $table->index('phone');
            $table->index('company_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
