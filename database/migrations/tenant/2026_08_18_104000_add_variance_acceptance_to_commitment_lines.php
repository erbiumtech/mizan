<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one part of the three-way match that is stored — `docs/construction-management-plan.md` §5.
 *
 * §5: the match itself — "ordered against received against invoiced, per commitment line" — is **computed, not
 * stored**, "with one exception: accepting a variance is a human decision, so `match_status = 'accepted'`, who
 * accepted it and the reason *are* stored. A decision with no record is not a control."
 *
 * So three columns, and deliberately not a fourth. **`match_status` itself is derived** rather than stored: it is a
 * function of the ordered, received and invoiced figures, and an acceptance is what turns whatever it says into
 * *accepted*. Storing the status as well would give the row two ways to answer the same question, and the first thing
 * that happens then is a status that stops agreeing with the figures underneath it — which is exactly the failure
 * `open_amount` was left out of the commitment line to avoid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('construction_commitment_lines', function (Blueprint $table) {
            // The three that make the acceptance a control rather than a click: when, who, and why.
            $table->timestamp('variance_accepted_at')->nullable()->after('cost_type');
            $table->unsignedBigInteger('variance_accepted_by')->nullable()->after('variance_accepted_at');
            $table->text('variance_reason')->nullable()->after('variance_accepted_by');
        });
    }

    public function down(): void
    {
        Schema::table('construction_commitment_lines', function (Blueprint $table) {
            $table->dropColumn(['variance_accepted_at', 'variance_accepted_by', 'variance_reason']);
        });
    }
};
