<?php

namespace App\Modules\Invoicing;

use App\Modules\Invoicing\Console\Commands\RaiseRecurringInvoices;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Invoicing\Policies\ContactPolicy;
use App\Modules\Invoicing\Policies\InvoiceLinePolicy;
use App\Modules\Invoicing\Policies\InvoicePolicy;
use App\Modules\Invoicing\Policies\TaxRatePolicy;
use App\Modules\Invoicing\Support\InvoicingReports;
use App\Support\DashboardStats;
use App\Support\JournalEntryOwners;
use App\Support\Reporting\ReportRenderers;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Invoicing module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class InvoicingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Contact::class => ContactPolicy::class,
        InvoiceLine::class => InvoiceLinePolicy::class,
        Invoice::class => InvoicePolicy::class,
        TaxRate::class => TaxRatePolicy::class,
    ];

    public function boot(): void
    {
        $this->commands([RaiseRecurringInvoices::class]);

        // An invoice's journal entry is the accounting half of the invoice, so the register must refuse
        // to edit it. Registered from here rather than named in Accounting: that naming was an
        // `accounting -> invoicing` edge, and Accounting does not require Invoicing.
        JournalEntryOwners::register('an invoice', Invoice::class);

        // Invoicing's own three reports. See PayrollReports for the other half of the same change.
        foreach (['AgedReceivables', 'AgedPayables'] as $key) {
            ReportRenderers::register(
                $key,
                fn (string $asOf): array => app(InvoicingReports::class)->ageing($key, $asOf),
            );
        }

        ReportRenderers::register(
            'FbrInvoiceReporting',
            fn (string $asOf): array => app(InvoicingReports::class)->fbrReconciliation($asOf),
        );

        DashboardStats::register('invoicing.unpaid', function () {
            if (! auth()->user()?->can('InvoiceView')) {
                return null;
            }

            // Single aggregate query instead of loading every open invoice.
            //
            // Credit notes are subtracted from the money and left out of the count, which is two
            // different decisions. The money has to net or this figure overstates what is owed by every
            // credit outstanding. The count must not, because "3 open" should mean three invoices
            // somebody can chase — counting a credit note among them invites a call about a document the
            // customer is owed rather than owes.
            $open = Invoice::whereIn('kind', [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE])
                ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END), 0) as cnt, '
                    .'COALESCE(SUM((total - amount_paid) * CASE WHEN kind = ? THEN -1 ELSE 1 END), 0) as outstanding_total',
                    [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE]
                )
                ->first();

            return Stat::make(
                'Unpaid Customer Invoices',
                'PKR '.number_format(round((float) $open->outstanding_total, 2), 2),
            )->description(((int) $open->cnt).' open');
        }, sort: 30);

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/console.php');
    }
}
