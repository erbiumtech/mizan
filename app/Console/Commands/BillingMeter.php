<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\Company;
use App\Support\Subscriptions;
use Illuminate\Console\Command;
use Throwable;

/**
 * Count every subscribed company's active employees and write the number onto
 * its agreement — docs/saas-launch-plan.md §6. Monthly on the 1st, before the
 * invoicing module raises the drafts at 03:00.
 *
 * Keeps going after a failure and exits non-zero, BackupTenants' rule: one
 * customer with two agreements must not leave the other forty unmetered, and
 * must not be reported as success either.
 */
class BillingMeter extends Command
{
    protected $signature = 'billing:meter {--company=* : Limit to these company slugs}';

    protected $description = "Write each subscribed company's active-employee count onto its subscription agreement";

    public function handle(Subscriptions $subscriptions): int
    {
        if (! $subscriptions->isConfigured()) {
            $this->warn('SAAS_OPERATOR_COMPANY is not set; the platform bills nobody. Nothing metered.');

            return self::SUCCESS;
        }

        $companies = Company::query()->whereNotNull('billing_contact_id')->orderBy('id');

        if ($slugs = $this->option('company')) {
            $companies->whereIn('slug', $slugs);
        }

        $failed = [];
        $rows = [];

        foreach ($companies->get() as $company) {
            try {
                $rows[] = [$company->slug, $company->plan, $subscriptions->meter($company)];
            } catch (Throwable $e) {
                $this->error("  {$company->slug}: {$e->getMessage()}");
                $failed[] = $company->slug;
            }
        }

        if ($rows !== []) {
            $this->table(['Company', 'Plan', 'Active employees'], $rows);
        }

        if ($failed !== []) {
            $this->error(count($failed).' not metered: '.implode(', ', $failed));

            return self::FAILURE;
        }

        $this->info('Metered '.count($rows).' compan'.(count($rows) === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
