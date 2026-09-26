<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carried days can now lapse before the year ends — "they lapse on 31 March".
 *
 * The additive column docs/hrms-plan.md §4.1 promised "when somebody asks";
 * somebody has asked. Stamped by the year-end reset from
 * leave.carry_forward_expiry_months as it stands at that moment — the same rule as
 * the leave-year window on this table: a setting decides what happens next, never
 * what already happened, so changing the expiry policy in June cannot move a date
 * a year already began with.
 *
 * Null means the carried days do not expire early — either the company has no
 * expiry policy, nothing carried, or the lapse job has already dealt with it (the
 * job nulls the date once the lapse is written, which is what makes the daily
 * sweep idempotent; the lapse itself survives as a leave_adjustments row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_entitlements', function (Blueprint $table) {
            $table->date('carried_in_expires_on')->nullable()->after('carried_in_days')
                ->comment('Carried days lapse after this date; stamped by the reset, cleared by the lapse job');
        });
    }

    public function down(): void
    {
        Schema::table('leave_entitlements', function (Blueprint $table) {
            $table->dropColumn('carried_in_expires_on');
        });
    }
};
