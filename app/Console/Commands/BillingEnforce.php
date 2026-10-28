<?php

namespace App\Console\Commands;

use App\Support\Subscriptions;
use Illuminate\Console\Command;

/**
 * Suspend lapsed trials and long-overdue customers, lift the suspensions that
 * have been put right, and never touch one a person imposed —
 * docs/saas-launch-plan.md §6. Daily.
 */
class BillingEnforce extends Command
{
    protected $signature = 'billing:enforce';

    protected $description = 'Suspend lapsed trials and overdue customers; lift suspensions that have been put right';

    public function handle(Subscriptions $subscriptions): int
    {
        if (! $subscriptions->isConfigured()) {
            $this->warn('SAAS_OPERATOR_COMPANY is not set; the platform bills nobody. Nothing enforced.');

            return self::SUCCESS;
        }

        $result = $subscriptions->enforce();

        foreach ($result['suspended'] as $slug) {
            $this->warn("  suspended: {$slug}");
        }

        foreach ($result['lifted'] as $slug) {
            $this->info("  lifted: {$slug}");
        }

        $this->line(count($result['suspended']).' suspended, '.count($result['lifted']).' lifted.');

        return self::SUCCESS;
    }
}
