<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An objection somebody can answer, and the release that answering it authorises.
 *
 * A rejected payslip was described as "advisory", and for the payslip it is — the figures can still be
 * corrected. For the **money** it is not: `Payment::isReleasable()` holds a salary back until the payslip is
 * accepted, and `recordEmployeeReview()` refuses a second review. So an employee who rejected a payslip that
 * turned out to be right left their own salary in a state nothing could clear from a screen.
 *
 * **The conversation is the comment thread, not a column.** Payslips have had comments since they existed —
 * threaded, resolvable, and readable by the employee on their own payslip (`CommentPolicy` asks the model
 * who owns it). So the objection is written into that thread as its first comment, both sides reply there,
 * and the columns here hold only what the thread cannot: which comment started it, and the decision to
 * release taken at the end.
 *
 * `review_objection_comment_id` is that link. It could have been "the oldest comment", and that would be
 * wrong the first time somebody comments on a payslip *before* the employee objects to it.
 *
 * **`employee_review` becomes a string, which is the second half of this migration and the older bug.** It
 * was created as `enum('pending', 'accepted', 'rejected')`, and the fourth value cannot be stored in that.
 * Laravel renders `enum` as a plain varchar on SQLite — the default tenant driver, and what the test suite
 * runs on — so nothing local would ever have noticed; `.env.example` documents `TENANT_DB_DRIVER=mysql` for
 * production, where the ENUM is real and the insert is either rejected or silently emptied. Exactly the trap
 * `2026_08_28_120000_widen_invoice_kind_for_notes.php` found on `invoices.kind` a day earlier, and the same
 * answer: the model's constants are the authority. `payments.subject_review`, which is a copy of this
 * column, was already a string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            // 20 rather than the default 255: the longest value is `overridden`, and this column is filtered
            // and grouped on in the payslips list.
            $table->string('employee_review', 20)->default('pending')->change();
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->foreignId('review_objection_comment_id')
                ->nullable()
                ->after('employee_review_recorded_by_name')
                ->constrained('comments')->nullOnDelete()
                ->comment('The comment the rejection reason was written into — the head of the conversation');

            // Soft references to landlord `users`, as `employee_review_recorded_by` is: no foreign key can
            // span the two databases, and the name is snapshotted so the note survives a rename or deletion.
            $table->unsignedBigInteger('review_overridden_by')
                ->nullable()
                ->after('review_objection_comment_id')
                ->comment('Soft ref -> landlord users. Who closed the objection and released the salary');

            $table->string('review_overridden_by_name')
                ->nullable()
                ->after('review_overridden_by')
                ->comment('Snapshot, so the note survives a rename or deletion');

            $table->timestamp('review_overridden_at')
                ->nullable()
                ->after('review_overridden_by_name');
        });
    }

    /**
     * The columns go; the type does not.
     *
     * Narrowing `employee_review` back to three values would mean deciding what to do with every payslip
     * holding the fourth — refuse, or rewrite somebody's payroll history. Dropping these columns is enough to
     * undo this migration; leaving a varchar where an enum was breaks nothing.
     *
     * **Each column is dropped only if it is there**, which is not defensive programming for its own sake.
     * `down()` runs against whatever the database actually holds, and a database that received an earlier
     * shape of this change — as every developer machine that ran this file before it was rewritten did —
     * has some of these columns and not others. A rollback that throws on the first missing one leaves the
     * schema half-undone and the migration still recorded as applied, which is a worse place to be than
     * either end.
     */
    public function down(): void
    {
        if (Schema::hasColumn('payslips', 'review_objection_comment_id')) {
            Schema::table('payslips', function (Blueprint $table) {
                $table->dropConstrainedForeignId('review_objection_comment_id');
            });
        }

        foreach (['review_override_reply', 'review_overridden_by', 'review_overridden_by_name', 'review_overridden_at'] as $column) {
            if (Schema::hasColumn('payslips', $column)) {
                Schema::table('payslips', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
