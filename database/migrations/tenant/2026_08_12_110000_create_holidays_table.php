<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's non-working days, in one place because several modules read them
 * and none of them owns the answer.
 *
 * Leave and attendance both have to decide whether a given day counts, and so
 * will any future timesheet validation. The precedent is FiscalYear, which sits
 * in Core for exactly that reason. The alternative considered and rejected was a
 * copy of the calendar in each module: two lists of public holidays disagreeing
 * about Eid is a support call, not a design. See docs/hrms-plan.md §3.
 *
 * `is_recurring` marks a date as one to offer again next year. It computes
 * nothing: Eid moves with the lunar calendar, and a calendar that guessed would
 * be wrong every year in a way nobody notices until payroll has run on it. The
 * flag seeds next year's list as a draft for a human to confirm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();

            // Unique because two rows for the same day is a support call: every
            // consumer asks "is this date a holiday", and a duplicate makes the
            // answer depend on which row is read first — silently double-counted
            // by anything that sums instead of asking.
            //
            // This index is also what the range queries use. `between()` scans
            // date >= ? AND date <= ?, which a unique btree serves as well as a
            // plain one, so there is deliberately no second index on the same
            // column.
            $table->date('date')->unique();

            $table->string('name')->comment('As it appears on a calendar, e.g. "Eid ul-Fitr"');

            $table->boolean('is_recurring')->default(false)
                ->comment('Offer this date again next year — never auto-generated');

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
