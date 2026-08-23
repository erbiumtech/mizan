<?php

namespace App\Console\Commands;

use App\Modules\Construction\Services\LibraryInstaller;
use App\Modules\Core\Models\Company;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Give every company licensed for construction the reference data the module cannot work without.
 *
 * **Why a command rather than something automatic**, which is worth stating so the next person does not
 * assume it was laziness:
 *
 *  - **`$tenantSeeders` cannot do it.** Those run at `db:seed`, and construction is
 *    `'licensed_by_default' => false` — so at the moment a company's database is seeded the module is off,
 *    and a guarded seeder there would correctly skip every company forever.
 *  - **A `CompanyModule::saved()` hook is the shape that would be automatic, and it is not safe as things
 *    stand.** Licensing happens in the *platform* panel, where a super admin edits some other company and
 *    that company is not the current tenant. Reference data written from there lands in whichever database
 *    happens to be current — the failure `App\Support\TenantDb` exists to document. Doing it properly means
 *    making the company current around the write and restoring afterwards, inside a Core model event, and
 *    that is a decision about whether licensing provisions data rather than a detail to slip in behind a
 *    seeder.
 *
 * So: explicit, idempotent, and safe across every tenant, because it writes only where the module is
 * licensed. That is what makes running it after any licensing change a routine step rather than a risk.
 *
 * The work is in App\Modules\Construction\Services\LibraryInstaller; this is the tenant loop and the output.
 */
class InstallConstructionLibrary extends Command
{
    use TenantAware;

    protected $signature = 'construction:install-library
        {--tenant=* : Limit to these tenants (id, name or slug); defaults to all}
        {--dry-run : Say what would be installed without writing}';

    protected $description = 'Install the construction cost-code library and accounts for companies licensed for it';

    public function handle(LibraryInstaller $installer): int
    {
        $name = Company::current()?->name ?? 'unknown';

        if (! $installer->isLicensed()) {
            $this->line("  <fg=gray>{$name}: construction is not licensed — skipped.</>");

            return self::SUCCESS;
        }

        $existing = $installer->existingCodes();

        if ($this->option('dry-run')) {
            $this->line("  <fg=yellow>{$name}</>: would install the cost-code library"
                .($existing > 0 ? " (has {$existing} already; existing codes are updated, never duplicated)" : '')
                .($installer->wantsAccounts() ? ' and the construction accounts' : ''));

            return self::SUCCESS;
        }

        ['added' => $added, 'bookable' => $bookable, 'accounts' => $accounts] = $installer->install();

        $this->line("  <fg=green>{$name}</>: "
            .($added > 0 ? "{$added} cost codes added" : 'cost codes already present')
            .", {$bookable} bookable"
            .($accounts ? ', construction accounts installed' : '')
            .'.');

        return self::SUCCESS;
    }
}
