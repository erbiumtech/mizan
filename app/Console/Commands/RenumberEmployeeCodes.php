<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\Company;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeCodeRenumbering;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Renumber each company's employee codes into its own sequence, from one upward.
 *
 * **It rewrites identifiers that may already be in circulation** — on payslips, bank
 * files, printed reports and correspondence. That is why it is a command run
 * deliberately per tenant rather than a migration that happens to everyone on deploy,
 * and why `--dry-run` prints the whole mapping, with names, before anything is written.
 *
 * The decisions live in App\Modules\Employees\Services\EmployeeCodeRenumbering; this is
 * the tenant loop and the presentation.
 */
class RenumberEmployeeCodes extends Command
{
    use TenantAware;

    protected $signature = 'employees:renumber-codes
        {--tenant=* : Limit to these tenants (id, name or slug); defaults to all}
        {--prefix=EMP- : The code prefix to renumber}
        {--dry-run : Print the mapping without writing anything}
        {--force : Skip the confirmation prompt}';

    protected $description = "Renumber each company's employee codes into its own sequence from one";

    public function handle(EmployeeCodeRenumbering $renumbering): int
    {
        $prefix = (string) $this->option('prefix');
        $company = Company::current();

        $this->newLine();
        $this->line('<fg=gray>Tenant:</> '.($company?->name ?? 'unknown').' <fg=gray>→ '.($company?->database ?? '?').'</>');

        ['mapping' => $mapping, 'skipped' => $skipped] = $renumbering->plan($prefix);

        // Said out loud rather than silently omitted: a run that reports "done" while
        // having stepped over rows is the kind of quiet partial success nobody audits.
        if ($skipped->isNotEmpty()) {
            $this->newLine();
            $this->line("  <fg=yellow>Left alone</> — not <fg=gray>{$prefix}</><digits>:");
            $skipped->each(fn (string $code) => $this->line('    '.$code));
        }

        if ($mapping->isEmpty()) {
            $this->newLine();
            $this->info('  Nothing to renumber.');

            return self::SUCCESS;
        }

        $changing = $mapping->filter(fn (array $row): bool => $row['from'] !== $row['to'])->values();

        $this->newLine();
        $this->table(
            ['#', 'Employee', 'Joined', 'From', 'To'],
            $mapping->values()->map(fn (array $row, int $i): array => [
                $i + 1,
                Str::limit($row['name'] !== '' ? $row['name'] : '—', 28),
                $row['joined'] ?? '—',
                $row['from'],
                $row['from'] === $row['to'] ? '<fg=gray>unchanged</>' : "<fg=green>{$row['to']}</>",
            ])->all()
        );

        $this->line("  <fg=gray>{$changing->count()} of {$mapping->count()} would change.</>");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('  Dry run — nothing written.');

            return self::SUCCESS;
        }

        if ($changing->isEmpty()) {
            $this->newLine();
            $this->info('  Already in sequence — nothing written.');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Rewrite {$changing->count()} employee code(s) for ".($company?->name ?? 'this company').'?')) {
            $this->warn('  Aborted — nothing written.');

            return self::FAILURE;
        }

        $written = $renumbering->apply($changing);

        $this->newLine();
        $this->info("  Renumbered {$written} employee code(s).");

        return self::SUCCESS;
    }
}
