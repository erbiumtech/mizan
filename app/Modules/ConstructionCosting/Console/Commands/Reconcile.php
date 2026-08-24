<?php

namespace App\Modules\ConstructionCosting\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\Reconciliation;
use App\Modules\ConstructionCosting\Notifications\ReconciliationUnbalanced;
use App\Modules\ConstructionCosting\Services\ReconciliationService;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * §4.3's first mechanism — `docs/construction-management-plan.md` §4.3.
 *
 * **"Five mechanisms, because a report nobody opens is not a control."** This is the one that means nobody has to open
 * anything: it writes a row and notifies when unbalanced.
 *
 * **Every open period, not just the current one.** A difference that appeared in March and was never looked at does not
 * stop being a difference in July, and a command that only checked the current month would report a clean bill of health
 * on a company with four months of unexplained gaps behind it. Closed periods are skipped: their figures are what
 * somebody signed, they cannot change, and re-reporting them nightly would bury the one month that still can be fixed.
 *
 * **Named rather than counted in the output**, the discipline `CheckDelayNotices` set and every command since has kept:
 * "3 periods unbalanced" sends somebody hunting, and "March 412,900 — burden with no absorption account" does not.
 */
class Reconcile extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'construction:reconcile
                            {--period= : One cost month, as any date inside it. Default: every open period}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Prove the job-cost ledger against the general ledger, and say where it does not';

    public function handle(ReconciliationService $reconciler): int
    {
        if ($this->skipsDisabledModule('construction_costing')) {
            return self::SUCCESS;
        }

        $periods = $this->option('period')
            ? [CostPeriod::startFor($this->option('period'))->toDateString()]
            : CostPeriod::query()->open()->orderBy('period_start')->pluck('period_start')
                ->map(fn ($date): string => CostPeriod::startFor($date)->toDateString())->all();

        if ($periods === []) {
            $this->info('No open cost period to reconcile.');

            return self::SUCCESS;
        }

        $unbalanced = [];

        foreach ($periods as $periodStart) {
            $run = $reconciler->run($periodStart);

            if ($run->isBalanced()) {
                $this->info(CostPeriod::startFor($periodStart)->format('F Y').': balanced.');

                continue;
            }

            $unbalanced[] = $run;
            $this->warn(CostPeriod::startFor($periodStart)->format('F Y').': difference '
                .number_format((float) $run->difference, 2).'. '.$this->largestCause($run));
        }

        if ($unbalanced === []) {
            return self::SUCCESS;
        }

        /*
         * Whoever can act on it, which is whoever closes the period.
         *
         * Not `ConstructionCostView` — that is most of the commercial office, and a nightly notification to twenty
         * people is a notification twenty people filter into a folder. Not `ConstructionPeriodForceClose` either: that
         * is the grant for *accepting* a difference, and telling only the person who can wave it through is the wrong
         * shape entirely.
         */
        $recipients = User::holdingPermission('ConstructionPeriodClose')->where('status', 1)->get();

        if ($recipients->isNotEmpty()) {
            foreach ($unbalanced as $run) {
                Notification::send($recipients, new ReconciliationUnbalanced($run));
            }
        }

        /*
         * A non-zero exit, so a scheduler or CI that watches exit codes sees it.
         *
         * Deliberately not treated as an error inside the run: every period was reconciled and every row was written.
         * What failed is the company's bookkeeping, not this command, and the row is the deliverable either way.
         */
        return self::FAILURE;
    }

    /** The cause with the largest figure against it, in words. */
    private function largestCause(Reconciliation $run): string
    {
        $causes = collect($run->causeRows())
            ->filter(fn (array $row): bool => abs((float) ($row['amount'] ?? 0)) >= 0.01)
            ->sortByDesc(fn (array $row): float => abs((float) $row['amount']));

        $largest = $causes->first();

        return $largest === null
            ? 'No named cause accounts for it — usually a cost account posted to outside the control-account list.'
            : $largest['label'].' '.number_format((float) $largest['amount'], 2).'.';
    }
}
