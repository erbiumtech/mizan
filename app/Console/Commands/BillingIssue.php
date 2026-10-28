<?php

namespace App\Console\Commands;

use App\Support\Subscriptions;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Issue and send the period's subscription invoices — docs/saas-launch-plan.md §6.
 * Monthly on the 1st at 04:00, after the 03:00 raise; the three guards are in
 * Subscriptions::issue(). Exits non-zero when anything was held or failed, so a
 * quiet morning and a morning somebody has to look at are different exit codes.
 */
class BillingIssue extends Command
{
    protected $signature = 'billing:issue {--period= : Any date in the month to issue for; defaults to this month}';

    protected $description = "Issue and email the month's subscription invoices raised from auto-issue agreements";

    public function handle(Subscriptions $subscriptions): int
    {
        if (! $subscriptions->isConfigured()) {
            $this->warn('SAAS_OPERATOR_COMPANY is not set; the platform bills nobody. Nothing issued.');

            return self::SUCCESS;
        }

        $period = Carbon::parse($this->option('period') ?: now())->startOfMonth();
        $result = $subscriptions->issue($period);

        foreach (['issued' => 'info', 'held' => 'warn', 'failed' => 'error'] as $key => $style) {
            foreach ($result[$key] as $line) {
                $this->{$style}("  {$key}: {$line}");
            }
        }

        $this->line(sprintf(
            '%s: %d issued, %d held, %d failed.',
            $period->format('F Y'),
            count($result['issued']),
            count($result['held']),
            count($result['failed']),
        ));

        return ($result['held'] === [] && $result['failed'] === []) ? self::SUCCESS : self::FAILURE;
    }
}
