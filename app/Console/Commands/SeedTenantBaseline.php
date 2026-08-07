<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\Company;
use Database\Seeders\PersonalBaselineSeeder;
use Database\Seeders\SalarySlabSeeder;
use Database\Seeders\TenantBaselineSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Support\TenantTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Top up every company's baseline reference data.
 *
 *   php artisan tenants:seed-baseline
 *   php artisan tenants:seed-baseline --tenant=6
 *   php artisan tenants:seed-baseline --pretend
 *
 * Provisioning seeds a company's baseline once, at creation. Anything added to
 * that baseline in a later release therefore reaches new companies and no
 * existing one — which is how demo-company came to have no tax schedules at all,
 * silently, months after the rest of its data was fine. There was no command to
 * fix that short of knowing which seeder to run by hand against which tenant.
 *
 * SAFETY. This runs the same baseline seeder provisioning uses, and every seeder
 * in it is firstOrCreate or updateOrCreate — it adds what is missing and leaves
 * rows alone otherwise. The one exception is deliberately excluded:
 * SalarySlabSeeder deletes a fiscal year's slabs and recreates them, so running
 * it over a company that has corrected its own tax rates by hand would throw
 * that work away. It is skipped for any company that already has slabs — see
 * seedersFor().
 *
 * Idempotent by design: running it twice changes nothing the second time.
 */
class SeedTenantBaseline extends Command
{
    protected $signature = 'tenants:seed-baseline
        {--tenant= : Only this company id}
        {--pretend : Report what is missing without writing anything}';

    protected $description = "Add any missing baseline reference data to existing companies' databases";

    public function handle(): int
    {
        $companies = Company::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No companies matched.');

            return self::FAILURE;
        }

        $pretend = (bool) $this->option('pretend');

        foreach ($companies as $company) {
            $this->line("<fg=gray>—</> {$company->name} <fg=gray>({$company->typeLabel()})</>");

            try {
                $company->makeCurrent();

                $before = $this->counts($company);

                if ($pretend) {
                    $this->reportGaps($before);

                    continue;
                }

                foreach ($this->seedersFor($company) as $seeder) {
                    Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
                }

                $this->reportChanges($before, $this->counts($company));
            } catch (\Throwable $e) {
                // One company's failure must not stop the rest: a half-migrated
                // or unreachable tenant is exactly the sort this is meant to find.
                $this->error("  failed: {$e->getMessage()}");
            } finally {
                Company::forgetCurrent();
            }
        }

        return self::SUCCESS;
    }

    /**
     * The baseline seeders to run against a company that already exists.
     *
     * Everything in the baseline is firstOrCreate or updateOrCreate and so adds
     * what is missing without disturbing what is there — except SalarySlabSeeder,
     * which deletes a fiscal year's slabs and recreates them. That is correct at
     * provisioning and correct as the deliberate way to apply a new Finance Act,
     * and wrong here: a company that has corrected its own rates by hand would
     * lose that work to a command whose whole promise is that it only adds.
     *
     * So it runs only where there are no slabs at all, which is the case this
     * command exists for.
     *
     * @return array<int, class-string>
     */
    private function seedersFor(Company $company): array
    {
        $seeders = $company->isPersonal()
            ? PersonalBaselineSeeder::seeders()
            : TenantBaselineSeeder::seeders();

        if ($this->hasSalarySlabs()) {
            $seeders = array_values(array_filter(
                $seeders,
                fn (string $seeder) => $seeder !== SalarySlabSeeder::class,
            ));
        }

        return $seeders;
    }

    private function hasSalarySlabs(): bool
    {
        try {
            return DB::connection(TenantTransaction::connectionName())
                ->table('salary_slabs')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Row counts on the CURRENT TENANT's connection.
     *
     * Explicitly named, because makeCurrent() points the tenant connection at
     * this company's database but leaves the default connection on the landlord.
     * A bare DB::table('banks') therefore asks the landlord for a table it does
     * not have, throws, and lands in the -1 branch below — which reported every
     * company as "nothing missing" while two of them were plainly missing data.
     *
     * @return array<string, int>
     */
    private function counts(Company $company): array
    {
        // Only what this kind of tenant is meant to have. A personal account has
        // no salary slabs on purpose — it does not run payroll — and listing that
        // as a gap would send somebody chasing data that is absent by design.
        $tables = ['fiscal_years', 'currencies', 'accounts', 'banks', 'transaction_types', 'tax_schedules'];

        if (! $company->isPersonal()) {
            $tables[] = 'salary_slabs';
        }

        $counts = [];

        foreach ($tables as $table) {
            try {
                $counts[$table] = DB::connection(TenantTransaction::connectionName())
                    ->table($table)
                    ->count();
            } catch (\Throwable) {
                $counts[$table] = -1; // table absent — the tenant needs migrating first
            }
        }

        return $counts;
    }

    /** @param  array<string, int>  $before */
    private function reportGaps(array $before): void
    {
        $empty = array_keys(array_filter($before, fn (int $n) => $n === 0));

        $this->line($empty === []
            ? '  <fg=green>nothing missing</>'
            : '  would seed: '.implode(', ', $empty));
    }

    /**
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     */
    private function reportChanges(array $before, array $after): void
    {
        $added = [];

        foreach ($after as $table => $count) {
            $delta = $count - ($before[$table] ?? 0);

            if ($delta > 0) {
                $added[] = "{$table} +{$delta}";
            }
        }

        $this->line($added === []
            ? '  <fg=gray>already complete</>'
            : '  <fg=green>'.implode(', ', $added).'</>');
    }
}
