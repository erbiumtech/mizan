<?php

namespace App\Modules\Payroll\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Company;
use App\Modules\Payroll\Services\PayrollAutoPosting;
use App\Modules\Payroll\Services\PendingPayrollPoster;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Post payroll journal entries that never reached the ledger, per company.
 *
 *   php artisan payroll:post-pending --dry-run   # list what would post
 *   php artisan payroll:post-pending             # post it
 *
 * Why this exists: with auto-posting off, a payslip's entry stops at `pending_approval` and
 * balances do not move, so a paid month leaves 2300 Salaries Payable negative — money paid
 * against a liability that was never recorded. The logic lives in PendingPayrollPoster; this
 * only formats it.
 */
class PostPendingPayrollEntries extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'payroll:post-pending
        {--tenant=* : Limit to these tenants (id, name or slug); defaults to all}
        {--dry-run : List what would be posted and change nothing}';

    protected $description = 'Approve and post payroll journal entries left unposted';

    public function handle(PendingPayrollPoster $poster, PayrollAutoPosting $autoPosting): int
    {
        // Payroll needs Accounting to post at all, but it is Payroll's own backlog, so it is
        // Payroll that gates the command — the same way PayrollAccountAudit's does.
        if ($this->skipsDisabledModule('payroll')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=gray>Company:</> '.(Company::current()?->name ?? 'unknown'));

        $pending = $poster->pending();

        if ($pending->isEmpty()) {
            $this->info('Nothing pending — every payroll entry is posted.');

            // Said here rather than nowhere: a company with no backlog and auto-posting off
            // is one payroll run away from having one.
            if (! $autoPosting->isEnabled()) {
                $this->line('<fg=yellow>Note:</> auto-posting is off, so the next payslip will need posting too.');
                $this->line('Turn it on with <fg=yellow>php artisan payroll:auto-post --on</> if that is not deliberate.');
            }

            return self::SUCCESS;
        }

        $this->table(
            ['Entry', 'Date', 'Status', 'Memo', 'Debits'],
            $pending->map(fn ($entry): array => [
                $entry->entry_number,
                $entry->entry_date?->toDateString() ?? '—',
                $entry->status,
                str($entry->memo ?? '')->limit(40),
                number_format((float) $entry->total_debits, 2),
            ])->all(),
        );

        $payable = static::salariesPayableBalance();

        if ($this->option('dry-run')) {
            $this->info($pending->count().' entry(ies) would be posted. Nothing was changed.');
            $this->line('2300 Salaries Payable is <fg=yellow>'.number_format($payable, 2).'</> and will rise by the net salary accrued.');

            return self::SUCCESS;
        }

        ['posted' => $posted, 'failed' => $failed] = $poster->post();

        if ($posted !== []) {
            $this->newLine();
            $this->info('Posted '.count($posted).' entry(ies): '.implode(', ', $posted));
            $this->line(
                '2300 Salaries Payable: '.number_format($payable, 2)
                .' → <fg=green>'.number_format(static::salariesPayableBalance(), 2).'</>'
            );
            // Approved with no approver, and worth saying out loud rather than leaving to be
            // discovered in the audit log. See PendingPayrollPoster::approveAsSystem().
            $this->line('<fg=gray>Approved as a system action — approved_by is null on these entries.</>');
        }

        if ($failed !== []) {
            $this->newLine();
            $this->error(count($failed).' entry(ies) could not be posted:');

            foreach ($failed as $number => $message) {
                $this->line("  - {$number}: {$message}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The liability the whole problem shows up in. Null-safe: a chart without 2300 is a
     * different complaint, and payroll:accounts is the command that makes it.
     */
    protected static function salariesPayableBalance(): float
    {
        return (float) (Account::where('code', '2300')->value('balance') ?? 0);
    }
}
