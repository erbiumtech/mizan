<?php

namespace App\Modules\Attendance\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Attendance\Services\CompensatoryOffAccrual;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Phase 2a: credit time in lieu for days off that were worked, and expire what has
 * aged out.
 *
 * Daily and idempotent. The credit is recomputed from the qualifying attendance days
 * inside the expiry window rather than incremented, so running twice in one day cannot
 * double anybody's balance and a day the scheduler missed costs nothing.
 *
 * Expiry is why this cannot be a one-off calculation at the moment a day is worked: a
 * credit earned ninety-one days ago has to stop being spendable without anybody
 * touching it.
 */
class AccrueCompensatoryOff extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'attendance:accrue-comp-off {--tenant=*} {--date= : Treat this date as today}';

    protected $description = 'Credit compensatory off for worked days off, and expire credits past their window';

    public function handle(CompensatoryOffAccrual $accrual): int
    {
        if ($this->skipsDisabledModule('attendance')) {
            return self::SUCCESS;
        }

        $changed = $accrual->accrue($this->option('date'));

        $this->info($changed === 0
            ? 'No compensatory balances changed.'
            : "Updated compensatory balances for {$changed} employee(s).");

        return self::SUCCESS;
    }
}
