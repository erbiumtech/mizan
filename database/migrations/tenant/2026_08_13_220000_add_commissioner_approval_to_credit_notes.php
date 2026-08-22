<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Commissioner's extension, recorded — docs/fbr-digital-invoicing-plan.md §9.7.
 *
 * Open question 7 asked whether a credit note is a sufficient correction after 72 hours or
 * whether Commissioner approval is required even for that. The answer turns out to be that
 * the question conflated two different rules, and both of them mention the Commissioner:
 *
 *  - **STGO 01 of 2026, 72 hours.** An electronic invoice may be cancelled, deleted or edited
 *    inside the FBR system only within 72 hours. Changing it after that needs the
 *    Commissioner's prior approval. This application refuses; it cannot obtain approval and
 *    must not pretend the invoice changed.
 *  - **Section 9 of the Sales Tax Act 1990, with rules 20–22 of the Sales Tax Rules 2006,
 *    180 days.** A debit or credit note is not an amendment of the invoice — it is a second
 *    document adjusting the tax, on defined grounds. It needs no approval to issue. But the
 *    adjustment is only admissible if the note is issued **within 180 days of the supply**,
 *    and the proviso lets the Commissioner extend that by a **further 180 days** on written
 *    request with reasons recorded.
 *
 * So a credit note is sufficient, and unapproved — until day 180. After that the company
 * needs the Commissioner, and what it needs them for is permission to be late, not permission
 * to correct. These two columns are where that permission is recorded.
 *
 * **Why record it rather than just allow an override.** A credit note issued on day 300 is
 * indistinguishable, from the outside, from one issued on day 300 with the Commissioner's
 * extension in hand — and exactly one of those is admissible. The reference is what makes the
 * difference visible to the person who has to defend the return, which is the same argument
 * `credit_reason` makes one column over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // The Commissioner's own reference for the written extension. A string rather than
            // a boolean, because "approved" is a claim and a reference is evidence — and the
            // reference is what an auditor asks for. Null is the normal state: most credit
            // notes are raised well inside 180 days and never need one.
            $table->string('commissioner_approval_ref')->nullable()->after('credit_reason')
                ->comment('Reference of the Commissioner extension under rule 22, where one was needed.');

            // When it was granted. Separate from the reference because the date is what the
            // extended window is checked against being plausible, and because "we have an
            // approval" with no date attached is the shape of an approval nobody can find.
            $table->date('commissioner_approved_on')->nullable()->after('commissioner_approval_ref')
                ->comment('Date the Commissioner granted the rule 22 extension.');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['commissioner_approval_ref', 'commissioner_approved_on']);
        });
    }
};
