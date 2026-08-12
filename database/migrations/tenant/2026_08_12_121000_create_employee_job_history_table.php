<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Effective-dated job facts, because today they are overwritten in place.
     *
     * `employees.designation`, `.department` and `.manager_id` are live values
     * with no history, so "who could approve for this employee in March" is
     * unanswerable — and leave approval routes through `manager_id` via
     * `App\Support\EmployeeAccess`. A request approved in March keeps its
     * `decided_by`, so the approval survives, but the authority behind it does
     * not. Every approval chain the HRMS plan adds inherits that hole. See
     * docs/hrms-plan.md §3.
     *
     * The `employees` columns stay exactly as they are and become the *current*
     * row — denormalised on purpose, because every existing query reads them and
     * none of them should have to learn about history to keep working.
     */
    public function up(): void
    {
        Schema::create('employee_job_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // The date the change took effect, not the date it was typed in — a
            // promotion is agreed on a Tuesday and entered on a Friday, and every
            // "as at date X" read must use the former.
            $table->date('effective_from');

            // The same three columns as on `employees`, same types, so a row here
            // is a straight snapshot of what those columns held from this date.
            $table->string('designation')->nullable();
            $table->string('department')->nullable();

            // Soft ref, matching `employees.manager_id`: no FK, so removing a
            // manager never cascades away somebody else's history or blocks the
            // delete. A history row naming a deleted manager is still the correct
            // answer to "who could approve in March".
            $table->unsignedBigInteger('manager_id')->nullable();

            // No column for this on `employees` yet — this table is where the fact
            // starts, and the denormalised current value follows if and when the
            // employee form grows the field.
            $table->string('employment_type')->nullable();

            // Why it changed: 'promotion', 'transfer', 'separation', free text.
            // `separation` is how the plan folds `left_on` into this table as one
            // more row rather than a fourth kind of record.
            $table->string('reason')->nullable();

            // Soft ref -> landlord users, exactly as employee_change_requests
            // .requested_by does it: `users` lives in the landlord database, so a
            // constraint here would point across connections and cannot exist.
            $table->foreignId('recorded_by')->nullable()->index();

            $table->timestamps();

            // The composite every read uses: "latest row for this employee with
            // effective_from <= X" is an index-only range scan on exactly this,
            // and it is the whole query pattern of the JobHistory service.
            $table->index(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_job_history');
    }
};
