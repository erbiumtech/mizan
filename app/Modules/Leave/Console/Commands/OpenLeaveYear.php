<?php

namespace App\Modules\Leave\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Leave\Services\LeaveEntitlementService;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Open the current leave year for everybody, and roll any year that has ended.
 *
 * Two jobs in one command because they are one question asked daily — "is every
 * active employee's current leave year open?" — and splitting them would mean a
 * company whose reset ran but whose open did not, on the one day of the year when
 * that matters.
 *
 * Order is not arbitrary: the roll comes first, because it is what writes
 * `carried_in_days` into the new year's row, and opening that row first would leave
 * the reset with nothing to carry into (firstOrNew finds an existing row and the
 * reset deliberately does not touch one).
 *
 * Idempotent throughout. Entitlements are keyed unique on
 * (employee, type, year start), the open refreshes the accrual and leaves the
 * credits, and the reset writes carried days only when the next year is genuinely
 * new — so a daily sweep over a settled company changes nothing and a second run on
 * 1 January cannot double an allowance.
 */
class OpenLeaveYear extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'leave:open-year {--tenant=*} {--date= : Treat this date as today, for backfilling a year}';

    protected $description = 'Open the current leave year for every active employee, rolling any year that has ended';

    public function handle(LeaveEntitlementService $entitlements): int
    {
        if ($this->skipsDisabledModule('leave')) {
            return self::SUCCESS;
        }

        $date = $this->option('date');

        $rolled = $entitlements->resetEndedYears($date);
        $opened = $entitlements->openYearFor($date);

        $this->info("Rolled {$rolled} ended leave year(s) and opened or refreshed {$opened} entitlement(s).");

        return self::SUCCESS;
    }
}
