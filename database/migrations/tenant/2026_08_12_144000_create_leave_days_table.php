<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per calendar day a request actually consumed. The point of the design.
 *
 * A request stores a range; the days it consumed are rows, because a request
 * spanning a weekend and a public holiday consumes fewer days than its range
 * implies, and payroll needs to know *which* days fell in *which* month. Without
 * per-day rows a leave from 28 January to 3 February cannot be split across two
 * payslips, and the second month is wrong. docs/hrms-plan.md §4.1.
 *
 * Generated once, when the request is approved, and never recomputed. A holiday
 * added retroactively must not silently change a settled month — which is the same
 * rule leave.sandwich_rule and leave.year_basis follow, stated once in
 * docs/hrms-plan.md §4.7: a setting decides what happens next, never what already
 * happened.
 *
 * ACCEPTED LIMITATION, recorded because docs/hrms-plan.md §3 asked for a decision
 * rather than a silence: these rows record which days *were* consumed, not why a
 * date inside the range was skipped. Reconstructing "this March differs from last
 * March because Eid moved" therefore depends on the holidays table still holding
 * the row it held then. Stamping a calendar version on the request was the
 * alternative; it is not taken, because the days consumed — the part that reaches
 * pay — are fully recorded here, and a version column would only explain the
 * absences. If holidays ever become deletable in bulk, revisit this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_days', function (Blueprint $table) {
            $table->id();

            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();

            $table->date('date');

            // 1.0 or 0.5. Half days yes, hours no: nothing else in this system
            // measures pay in hours, and a two-hour leave that cannot reduce pay is
            // a note rather than a leave record (docs/hrms-plan.md §4.1).
            $table->decimal('portion', 3, 1)->default(1.0)
                ->comment('1.0 or 0.5 — the granularity the whole module rounds to');

            // Copied from the leave type at generation, not read through the
            // relation. The type's is_paid may be corrected later, and a day
            // already taken and already on a payslip must keep the character it had
            // when it was settled — the same reason the proration divisor is
            // recorded on the payslip rather than recomputed.
            $table->boolean('is_paid')
                ->comment('Copied from the type at generation, so a later correction cannot restate a settled month');

            $table->timestamps();

            // A request cannot consume the same day twice. This is also the index
            // the generator's existence checks use.
            $table->unique(['leave_request_id', 'date']);

            // The payroll-month read: every leave day falling in a given month,
            // which is what splits a request across two payslips.
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_days');
    }
};
