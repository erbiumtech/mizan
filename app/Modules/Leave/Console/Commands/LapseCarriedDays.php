<?php

namespace App\Modules\Leave\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Leave\Services\LeaveEntitlementService;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Lapse carried-forward days whose expiry date has passed.
 *
 * The job half of docs/hrms-plan.md §4.1's "an additive column plus a job when
 * somebody asks". The year-end roll stamps `carried_in_expires_on` from
 * leave.carry_forward_expiry_months; this sweep voids what is left of the carried
 * days as an adjustment row and clears the date, which is what makes it idempotent
 * — the same daily-not-annually reasoning as leave:open-year, because "31 March"
 * is a different date per year basis and the host may have been down on the one
 * morning it mattered.
 */
class LapseCarriedDays extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'leave:lapse-carried-days {--tenant=*} {--date= : Treat this date as today, for backfilling}';

    protected $description = 'Void carried-forward leave days whose expiry date has passed';

    public function handle(LeaveEntitlementService $entitlements): int
    {
        if ($this->skipsDisabledModule('leave')) {
            return self::SUCCESS;
        }

        $lapsed = $entitlements->lapseExpiredCarriedDays($this->option('date'));

        $this->info("Lapsed expired carried days on {$lapsed} entitlement(s).");

        return self::SUCCESS;
    }
}
