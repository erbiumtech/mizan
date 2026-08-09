<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Accounting\Services\ScheduledTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Raise the journal entries every standing schedule is due for, as drafts.
 *
 * Daily rather than monthly, because a schedule may name any day of the month —
 * and because running daily is what makes catching up after an outage the normal
 * path rather than a special case.
 */
class RaiseScheduledTransactions extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'accounting:raise-scheduled
                            {--up-to= : Raise everything due on or before this date. Defaults to today}
                            {--dry-run : List what would be raised without writing anything}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Raise draft journal entries for any standing schedule that is due';

    public function handle(ScheduledTransactionService $scheduled): int
    {
        if ($this->skipsDisabledModule('accounting')) {
            return self::SUCCESS;
        }

        $upTo = CarbonImmutable::parse($this->option('up-to') ?: now())->startOfDay();
        $due = $scheduled->due($upTo);

        if ($due->isEmpty()) {
            $this->line('Nothing due on or before '.$upTo->toDateString().'.');

            return self::SUCCESS;
        }

        $capped = [];

        $this->table(
            ['Schedule', 'Every', 'Due', 'Dates', 'State'],
            $due->map(function ($schedule) use ($scheduled, $upTo, &$capped): array {
                $dates = $scheduled->outstandingFor($schedule, $upTo);

                if (count($dates) === ScheduledTransactionService::MAX_PER_RUN) {
                    $capped[] = $schedule->name;
                }

                return [
                    $schedule->name,
                    $schedule->intervalLabel(),
                    count($dates),
                    collect($dates)->map->toDateString()->take(3)->implode(', ')
                        .(count($dates) > 3 ? ', …' : ''),
                    $schedule->isBalanced() ? 'to raise' : 'unbalanced — skipped',
                ];
            })->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $raised = $scheduled->run($upTo);

        $this->info("Raised {$raised->count()} draft entry(ies). Review them under Journal Entries before submitting.");

        // Said out loud rather than left to be inferred from a number that looks
        // round: a run that stopped at the cap has left work behind, and silence
        // there reads as "everything is up to date".
        if ($capped !== []) {
            $this->warn(
                'Stopped at '.ScheduledTransactionService::MAX_PER_RUN.' entries for: '.implode(', ', $capped).'. '
                .'The rest will be raised on the next run — check the start date if that is not what you expected.'
            );
        }

        $skipped = $due->reject->isBalanced();

        if ($skipped->isNotEmpty()) {
            $this->warn('Skipped as unbalanced: '.$skipped->pluck('name')->implode(', ').'.');
        }

        return self::SUCCESS;
    }
}
