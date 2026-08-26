<?php

namespace App\Modules\Core\Console\Commands;

use App\Modules\Core\Jobs\DeliverScheduledReport;
use App\Modules\Core\Models\ReportSchedule;
use App\Support\Reporting\ReportDeliveryService;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Dispatch the reports that are due — `docs/reports-expansion-plan.md` Phase 8, item 5.
 *
 * > **The schedule entry is one line, per module.** A `reports:deliver` command in the reports module's own
 * > `routes/console.php`, `TenantAware`, `SkipsDisabledModules`, running every fifteen minutes and dispatching
 * > only the schedules whose cron says they are due — the `CheckEnvironmentsHealth` shape, so a thousand
 * > schedules still need one entry.
 *
 * **In Core, because there is no reports module.** The hub belongs to no module and every module puts reports
 * in it — the reason `Reports`, `ReportDefinition` and the dataset registry's consumers all live here. Which
 * also answers the item's `SkipsDisabledModules`: **there is nothing to skip.** Core is always on, so the
 * trait would guard a condition that cannot occur, and a report belonging to a module a company has switched
 * off is refused one layer down — `renderAs()` runs as the owner, whose module gating is what decides whether
 * the report resolves at all, and a schedule over an unavailable report is suspended rather than skipped
 * silently.
 *
 * `TenantAware`: with no `--tenant` option, `handle()` runs once per company with that company's connection
 * current.
 */
class DeliverScheduledReports extends Command
{
    use TenantAware;

    protected $signature = 'reports:deliver {--tenant=*}';

    protected $description = 'Dispatch the scheduled reports whose timetable says they are due';

    public function handle(ReportDeliveryService $reports): int
    {
        $due = $reports->due();

        foreach ($due as $schedule) {
            DeliverScheduledReport::dispatch($schedule->getKey());
        }

        $this->info(sprintf('Dispatched %d scheduled report(s) of %d.', $due->count(), ReportSchedule::query()->active()->count()));

        return self::SUCCESS;
    }
}
