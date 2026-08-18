<?php

namespace App\Console\Commands;

use App\Backup\TenantBackup;
use App\Modules\Core\Models\Company;
use Illuminate\Console\Command;
use Throwable;

/**
 * Back up every company's database, one archive each.
 *
 * The other half of `backup:run`, which covers the landlord and the uploads. See
 * {@see TenantBackup} for why the package cannot do this on its own.
 *
 * **Keeps going after a failure, and exits non-zero.** One company with a dropped database
 * must not stop the other forty being backed up — but it must also not be reported as success,
 * or the schedule quietly protects fewer companies each month.
 *
 * Not `TenantAware`: that trait switches the whole application into each tenant in turn, which
 * is for commands that operate *inside* a tenant. This one only needs each company's database
 * name, and staying on the landlord connection keeps the enumeration itself readable.
 */
class BackupTenants extends Command
{
    protected $signature = 'backup:tenants
                            {--company=* : Slugs to back up. Default is every company.}';

    protected $description = "Back up each company's tenant database to its own archive";

    public function handle(TenantBackup $backup): int
    {
        $companies = $backup->companies();

        if ($slugs = $this->option('company')) {
            $companies = $companies->whereIn('slug', $slugs);

            // A typo in a slug would otherwise back up nothing and say so quietly. On a
            // command whose whole job is "the data is safe", silence has to be impossible.
            if ($missing = array_diff($slugs, $companies->pluck('slug')->all())) {
                $this->error('No such company: '.implode(', ', $missing));

                return self::FAILURE;
            }
        }

        if ($companies->isEmpty()) {
            $this->warn('No companies have a database recorded, so nothing was backed up. This is not a pass.');

            return self::SUCCESS;
        }

        $failed = [];

        foreach ($companies as $company) {
            $this->info("Backing up {$company->slug} ({$company->database})…");

            try {
                if ($backup->run($company) !== self::SUCCESS) {
                    $failed[] = $company->slug;
                }
            } catch (Throwable $e) {
                // Caught per company, by design: see the class docblock.
                $this->error("  {$company->slug}: {$e->getMessage()}");
                $failed[] = $company->slug;
            }
        }

        $done = $companies->count() - count($failed);

        if ($failed !== []) {
            $this->error(count($failed).' of '.$companies->count().' failed: '.implode(', ', $failed));
            $this->line("{$done} succeeded.");

            return self::FAILURE;
        }

        $this->info("Backed up {$done} company database(s).");

        // Said every time, not only on failure. A tenant archive without a landlord archive
        // from a compatible moment restores a database that nothing can open — the companies
        // row that names it, and the users that reach it, are in the landlord.
        $this->newLine();
        $this->line('These are tenant databases only. Run backup:run as well: a tenant archive');
        $this->line('without a matching landlord archive restores a database nothing can open.');

        return self::SUCCESS;
    }
}
