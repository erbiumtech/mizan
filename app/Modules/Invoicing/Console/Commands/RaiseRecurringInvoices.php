<?php

namespace App\Modules\Invoicing\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Invoicing\Services\RecurringInvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Raise the month's recurring invoices as drafts.
 *
 * Drafts, not issued documents: an invoice reaching the ledger and the client is a
 * decision somebody makes after reading it, and a cron job is not somebody.
 */
class RaiseRecurringInvoices extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'invoicing:raise-recurring
                            {--period= : Any date in the month to bill. Defaults to the month being run}
                            {--dry-run : List what would be raised without writing anything}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = "Raise draft invoices for the month's recurring agreements";

    public function handle(RecurringInvoiceService $recurring): int
    {
        if ($this->skipsDisabledModule('invoicing')) {
            return self::SUCCESS;
        }

        $period = Carbon::parse($this->option('period') ?: now())->startOfMonth();
        $due = $recurring->due($period);

        if ($due->isEmpty()) {
            $this->line('No recurring invoices running in '.$period->format('F Y').'.');

            return self::SUCCESS;
        }

        $this->table(
            ['Agreement', 'Client', 'Lines', 'Total', 'Invoice date', 'State'],
            $due->map(fn ($agreement): array => [
                $agreement->description,
                $agreement->contact?->name ?? '—',
                $agreement->lines->count(),
                number_format($agreement->total(), 2),
                $agreement->invoiceDateFor($period)->toDateString(),
                match (true) {
                    $recurring->alreadyRaised($agreement, $period) => 'already raised',
                    $agreement->lines->isEmpty() => 'no lines — skipped',
                    default => 'to raise',
                },
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $raised = $recurring->generateFor($period);

        $this->info("Raised {$raised->count()} draft invoice(s) totalling "
            .number_format((float) $raised->sum('total'), 2).'. Review them under Invoices before issuing.');

        return self::SUCCESS;
    }
}
