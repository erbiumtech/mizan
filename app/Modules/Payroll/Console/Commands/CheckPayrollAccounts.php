<?php

namespace App\Modules\Payroll\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Core\Models\Company;
use App\Modules\Payroll\Services\PayrollAccountAudit;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Diagnose (and optionally repair) the payroll account-code mapping, per company.
 *
 *   php artisan payroll:accounts          # report
 *   php artisan payroll:accounts --fix    # clear bad overrides
 *
 * The logic lives in PayrollAccountAudit; this only formats it.
 */
class CheckPayrollAccounts extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'payroll:accounts
        {--tenant=* : Limit to these tenants (id, name or slug); defaults to all}
        {--fix : Clear overrides whose code is missing from the chart, falling back to the default}';

    protected $description = 'Verify that every payroll account code exists in the chart of accounts';

    public function handle(PayrollAccountAudit $audit): int
    {
        // Payroll needs Accounting to post, but the audit reports on Payroll's own
        // account mapping, so it is Payroll that gates it.
        if ($this->skipsDisabledModule('payroll')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=gray>Company:</> '.(Company::current()?->name ?? 'unknown'));

        $this->table(
            ['Payroll line', 'Code', 'From', 'Account'],
            collect($audit->report())->map(fn (array $row): array => [
                $row['key'],
                $row['code'] ?? '—',
                $row['source'],
                $row['account']?->name ?? '<fg=red>MISSING</>',
            ])->all(),
        );

        $broken = $audit->broken();

        if ($broken === []) {
            $this->info('All payroll account codes resolve.');

            return self::SUCCESS;
        }

        $this->error(count($broken).' payroll code(s) do not exist in this chart of accounts:');

        foreach ($broken as $key => $code) {
            $this->line("  - {$key} → ".($code ?? 'not set'));
        }

        if (! $this->option('fix')) {
            $this->newLine();
            $this->line('Re-run with <fg=yellow>--fix</> to clear the bad overrides, or correct them under');
            $this->line('Company Settings → Payroll → Payroll Account Codes.');

            return self::FAILURE;
        }

        ['cleared' => $cleared, 'unfixable' => $unfixable] = $audit->repair();

        if ($cleared !== []) {
            $this->newLine();
            $this->info('Cleared '.count($cleared).' override(s); the default now applies:');

            foreach ($cleared as $key => [$was, $now]) {
                $this->line("  - {$key}: ".($was ?? 'not set')." → {$now}");
            }
        }

        if ($unfixable !== []) {
            $this->newLine();
            $this->error('These need a code picked by hand — the shipped default is missing from this chart too:');

            foreach ($unfixable as $key => $code) {
                $this->line("  - {$key} → ".($code ?? 'not set')
                    .' (default: '.(config(PayrollAccountAudit::SETTING_KEY.'.'.$key) ?? 'none').')');
            }

            $this->line('Set them under Company Settings → Payroll, or seed the chart with ChartOfAccountsSeeder.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
