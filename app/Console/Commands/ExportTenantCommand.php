<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\Company;
use App\Support\TenantExport;
use App\Support\TenantStorage;
use Illuminate\Console\Command;
use Throwable;

/**
 * A company's data as one archive, from the console — docs/saas-launch-plan.md §9.
 * The same service the Subscription page's "Export my data" dispatches, run
 * here and now: for a leaving customer, a support request, or the step before
 * tenant:drop.
 */
class ExportTenantCommand extends Command
{
    protected $signature = 'tenant:export {tenant : Company slug}';

    protected $description = "Write a company's database dump and uploaded files into one archive in its own storage";

    public function handle(TenantExport $exports): int
    {
        $company = Company::query()->where('slug', $this->argument('tenant'))->first();

        if ($company === null) {
            $this->error("No company with slug [{$this->argument('tenant')}].");

            return self::FAILURE;
        }

        try {
            $path = $exports->run($company);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Exported {$company->name} to ".TenantStorage::publicRoot($company).'/'.$path);
        $this->line('Downloadable by the company\'s Administrators at '.$exports->url($company, $path));

        return self::SUCCESS;
    }
}
