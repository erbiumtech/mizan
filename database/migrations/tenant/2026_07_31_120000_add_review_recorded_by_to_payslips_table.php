<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who actually recorded an employee's acknowledgement.
 *
 * Accepting a payslip is a statement of consent, and an administrator signed in
 * as the employee can make it (see App\Support\Impersonation). The audit log
 * already carries `impersonated_by`, but the payslip itself is the document
 * anybody looks at — and an acknowledgement it shows as the employee's, when it
 * was entered on their behalf, is a claim about consent the record cannot support.
 *
 * The id is a soft reference: `users` lives in the landlord database while
 * `payslips` is per-company, so no foreign key can span them (same reason
 * employees.user_id has none). The name is snapshotted alongside it so the note
 * still reads if that account is later renamed or removed, and so showing it
 * needs no cross-database lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_review_recorded_by')
                ->nullable()
                ->after('employee_rejection_reason')
                ->comment('Soft ref -> landlord users. Set only when entered on the employee\'s behalf');

            $table->string('employee_review_recorded_by_name')
                ->nullable()
                ->after('employee_review_recorded_by')
                ->comment('Snapshot, so the note survives a rename or deletion');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['employee_review_recorded_by', 'employee_review_recorded_by_name']);
        });
    }
};
