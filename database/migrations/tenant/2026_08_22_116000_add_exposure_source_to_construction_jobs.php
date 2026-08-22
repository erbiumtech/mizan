<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a job's exposure hours come from — `docs/construction-management-plan.md` §17.6.
 *
 * **"Exposure hours are the denominator and nobody has them."** §17.6 names two failures around that sentence and this
 * column exists for the second one.
 *
 * The first is the *absence*: with no site diary the denominator is zero, every frequency rate renders as `0.00`, and
 * that reads as a perfect safety record when it means nobody filled anything in. §17.6 is unusually explicit that this
 * is "a genuine silent failure rather than a graceful degradation", and the answer is a page that says **"insufficient
 * exposure data"** and refuses to print a rate.
 *
 * The second is the *mirror image*: "double counting the same people from the diary **and** from Timesheets, which
 * halves every rate". A halved rate is worse than a missing one, because it looks like a number somebody can act on.
 * §17.6's remedy is exactly this column — "the job names one source and the report prints which one it used".
 *
 * **Nullable, and the null is a state the report has to name.** A job that has not chosen is not the same as a job with
 * no hours: the first needs somebody to decide and the second needs somebody to fill in a diary, and a default would
 * have quietly made that decision for every job in every existing tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('construction_jobs', function (Blueprint $table) {
            $table->enum('exposure_hours_source', ['daily_log', 'timesheets'])
                ->nullable()
                ->after('stock_location_id')
                ->comment('§17.6: one source only — counting both halves every safety rate');
        });
    }

    public function down(): void
    {
        Schema::table('construction_jobs', function (Blueprint $table) {
            $table->dropColumn('exposure_hours_source');
        });
    }
};
