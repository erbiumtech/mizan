<?php

namespace App\Modules\Payroll\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Core\Models\Company;
use App\Modules\Payroll\Services\PayrollAutoPosting;
use App\Modules\Payroll\Services\PendingPayrollPoster;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Whether payslips post their own journal entries, per company.
 *
 *   php artisan payroll:auto-post          # report where each company stands
 *   php artisan payroll:auto-post --on     # payslips post themselves
 *   php artisan payroll:auto-post --off    # entries wait for Manager/CEO approval
 *
 * Reading it takes one flag fewer than finding it in Company Settings for each company in
 * turn, which is the reason it is a command: the setting is per company, and "is this on
 * everywhere?" had no answer short of clicking through every one.
 */
class SetPayrollAutoPosting extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'payroll:auto-post
        {--tenant=* : Limit to these tenants (id, name or slug); defaults to all}
        {--on : Post payroll entries as they are created}
        {--off : Leave them pending_approval for a Manager or CEO}';

    protected $description = 'Show or change whether payroll journal entries post themselves';

    public function handle(PayrollAutoPosting $autoPosting, PendingPayrollPoster $poster): int
    {
        if ($this->skipsDisabledModule('payroll')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=gray>Company:</> '.(Company::current()?->name ?? 'unknown'));

        if ($this->option('on') && $this->option('off')) {
            $this->error('Pick one of --on or --off.');

            return self::FAILURE;
        }

        if (! $this->option('on') && ! $this->option('off')) {
            return $this->report($autoPosting, $poster);
        }

        $wanted = (bool) $this->option('on');
        $was = $autoPosting->isEnabled();

        $autoPosting->set($wanted);

        $this->line(
            'Auto-posting: '.static::label($was).' → '
            .($wanted ? '<fg=green>on</>' : '<fg=yellow>off</>')
            .($was === $wanted ? ' <fg=gray>(unchanged)</>' : '')
        );

        if ($wanted) {
            // The half of the fix this command cannot do. Said every time it is switched on,
            // because a backlog is invisible from here and stays unposted for ever otherwise.
            $backlog = $poster->pending()->count();

            $this->line(
                $backlog === 0
                    ? 'No backlog: every payroll entry already posted.'
                    : "<fg=yellow>{$backlog} entry(ies) are still unposted from before this change.</> "
                        .'They are not posted retroactively — run <fg=yellow>php artisan payroll:post-pending</>.'
            );

            $this->line('<fg=gray>Note: auto-posted entries carry no approver, so payroll bypasses Manager/CEO sign-off.</>');
        }

        return self::SUCCESS;
    }

    protected function report(PayrollAutoPosting $autoPosting, PendingPayrollPoster $poster): int
    {
        $enabled = $autoPosting->isEnabled();

        $this->line('Auto-posting: '.static::label($enabled));
        $this->line('<fg=gray>Shipped default (no choice stored):</> '.static::label($autoPosting->default()));

        $backlog = $poster->pending()->count();

        if ($backlog > 0) {
            $this->line("<fg=yellow>{$backlog} payroll entry(ies) unposted</> — see php artisan payroll:post-pending --dry-run");
        }

        return self::SUCCESS;
    }

    protected static function label(bool $enabled): string
    {
        return $enabled ? '<fg=green>on</>' : '<fg=yellow>off</>';
    }
}
