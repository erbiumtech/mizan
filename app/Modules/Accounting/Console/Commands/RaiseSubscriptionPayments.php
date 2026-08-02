<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Accounting\Services\SubscriptionBillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;
use Throwable;

/**
 * Raise the month's standing payments to beneficiaries, on the 26th (see the
 * module's routes/console.php).
 *
 * Drafts, into the same pool as everything else: they are reviewed, batched,
 * released, approved and posted exactly like a payment somebody typed.
 */
class RaiseSubscriptionPayments extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'accounting:raise-subscriptions
                            {--period= : Any date in the month to bill, e.g. 2026-08-01. Defaults to the month being run}
                            {--dry-run : List what would be raised without writing anything}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Raise draft payments for the month\'s beneficiary subscriptions';

    public function handle(SubscriptionBillingService $billing): int
    {
        if ($this->skipsDisabledModule('accounting')) {
            return self::SUCCESS;
        }

        $period = Carbon::parse($this->option('period') ?: now())->startOfMonth();
        $due = $billing->due($period);

        if ($due->isEmpty()) {
            $this->line('No subscriptions running in '.$period->format('F Y').'.');

            return self::SUCCESS;
        }

        $this->line($period->format('F Y').': '.$due->count().' subscription(s) running.');

        $this->table(
            ['Subscription', 'Beneficiary', 'Amount', 'Value date', 'State'],
            $due->map(fn ($subscription): array => [
                $subscription->description,
                $subscription->beneficiary?->name ?? '—',
                number_format((float) $subscription->amount, 2),
                $subscription->valueDateFor($period)->toDateString(),
                $billing->alreadyBilled($subscription, $period) ? 'already raised' : 'to raise',
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        try {
            $raised = $billing->generateFor($period);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Raised {$raised->count()} draft payment(s) totalling ".number_format((float) $raised->sum('amount'), 2).'.');

        return self::SUCCESS;
    }
}
