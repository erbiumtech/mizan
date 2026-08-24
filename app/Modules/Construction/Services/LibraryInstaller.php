<?php

namespace App\Modules\Construction\Services;

use App\Modules\Construction\Models\CostCode;
use Database\Seeders\ConstructionAccountsSeeder;
use Database\Seeders\ConstructionCostCodeSeeder;
use Illuminate\Database\Seeder;

/**
 * Install the reference data the construction module cannot work without, for the current company.
 *
 * `CostLedger::record()` requires a leaf cost code and nothing seeds one, so a company that licenses
 * construction arrives at the job-cost screens unable to book a single cost until somebody hand-builds a tree
 * through the UI. This is what closes that.
 *
 * Separate from the command that drives it for the same reason `EmployeeCodeRenumbering` is: the command is
 * `TenantAware`, which switches tenant databases, and the test suite cannot — it deliberately runs every
 * tenant in one database, so `SwitchTenantDatabaseTask` throws the moment a test invokes the command. The
 * decisions worth testing all live here.
 */
class LibraryInstaller
{
    /** Whether this company has bought construction at all. Nothing below writes if it has not. */
    public function isLicensed(): bool
    {
        return modules()->licensed('construction');
    }

    /**
     * The construction accounts follow the *costing* licence, not construction's.
     *
     * §18.2: retention receivable, materials on site and the three recovery accounts are accounting rows, and
     * a contractor keeping its books in another system has no use for them — the same argument that keeps
     * them out of every company's chart of accounts in the first place.
     */
    public function wantsAccounts(): bool
    {
        return modules()->licensed('construction_costing');
    }

    public function existingCodes(): int
    {
        return CostCode::count();
    }

    /**
     * Idempotent: the seeder matches on `code`, so a second run updates the library in place rather than
     * duplicating it. That matters because this is reference data a company may have edited — a renamed code
     * keeps its id, and every cost entry pointing at it stays pointing at it.
     *
     * @return array{added: int, bookable: int, accounts: bool}
     */
    public function install(): array
    {
        $before = $this->existingCodes();

        $this->run(ConstructionCostCodeSeeder::class);

        $accounts = $this->wantsAccounts();

        if ($accounts) {
            $this->run(ConstructionAccountsSeeder::class);
        }

        return [
            'added' => CostCode::count() - $before,
            'bookable' => CostCode::query()->bookable()->count(),
            'accounts' => $accounts,
        ];
    }

    /** @param  class-string<Seeder>  $seeder */
    private function run(string $seeder): void
    {
        // Without `setCommand()`, so the seeders stay silent: one line per tenant is what makes a run across
        // fifty of them readable.
        app($seeder)->__invoke();
    }
}
