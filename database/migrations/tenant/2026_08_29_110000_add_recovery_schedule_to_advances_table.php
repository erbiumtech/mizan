<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When recovery starts, and the months it is paused.
 *
 * An advance handed over in August is often not recovered from August's payroll —
 * the agreement says deductions begin in October — and a month is sometimes skipped
 * outright (Eid, a month of unpaid leave). Both were being done by typing over the
 * payslip's `advances` figure each time, which recovers the right amount but leaves
 * no record of the arrangement.
 *
 * Nullable and empty by default, so every existing advance keeps deducting from the
 * month it always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->date('recovery_starts_on')->nullable()->after('started_on')
                ->comment('First payroll month to deduct from; null means deduct from the start');
            $table->json('skipped_months')->nullable()->after('recovery_starts_on')
                ->comment('Payroll months to take nothing in, as Y-m');
        });
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->dropColumn(['recovery_starts_on', 'skipped_months']);
        });
    }
};
