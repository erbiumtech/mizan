<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent, contract, probation or intern — the fourth job fact.
 *
 * `employee_job_history` has carried an `employment_type` column since it was
 * created, and nothing could ever fill it: there was no counterpart on
 * `employees`, so `JobHistory::captureCurrent()` had nothing to snapshot and
 * every row stored null. That is the state this codebase already refuses
 * elsewhere — the `create_invoice_events_table` migration declined a `viewed`
 * column on the grounds that "inventing a column that never fills would be worse
 * than its absence". The column was worth keeping and the gap was the missing
 * half, so this adds it rather than dropping the other side.
 *
 * Nullable with no default and no backfill. Every existing employee has an
 * employment type in reality and this application has never recorded it;
 * stamping them all `permanent` would look like a fact somebody entered. Null
 * reads as "nobody has said", which is true.
 *
 * NOT self-service. It is a job fact like designation and department, so it stays
 * out of EmployeeChangeRequest::ALLOWED_FIELDS and an employee cannot promote
 * themselves off probation. Changing it writes a dated history row, which is the
 * point — probation → permanent is exactly the change a final settlement and a
 * gratuity calculation need to be able to date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // A string rather than an enum: an enum change is a table rebuild on
            // MySQL and unsupported on SQLite, and the set of employment types
            // varies by company. Validated in the form, not in the schema — the
            // same choice `leaving_reason` made two migrations ago.
            $table->string('employment_type')->nullable()->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employment_type');
        });
    }
};
