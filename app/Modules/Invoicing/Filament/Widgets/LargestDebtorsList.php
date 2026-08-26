<?php

namespace App\Modules\Invoicing\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\Reporting\DashboardCache;
use App\Support\Reporting\DashboardWidgets;
use Filament\Widgets\Widget;

/**
 * The five largest debtors, with how late they are — `docs/reports-expansion-plan.md` Phase 5.5.
 *
 * **Fed by `InvoiceService::outstandingReceivables()`, which is the service behind the Aged Receivables
 * report** — the same call, aggregated by contact rather than listed by invoice. Phase 5's rule again: a
 * second query summing `invoices.total - paid` would have been quicker and would have been the thing the rule
 * forbids. Because both read the same rows, the top debtor here is by construction the same figure the ageing
 * report shows for that contact.
 *
 * **Aggregated by contact, because a debtor is a person and the report lists invoices.** The plan asks for
 * debtors; a list of the five largest *invoices* would put one customer in it three times and answer a
 * different question.
 *
 * **Days overdue is the worst of their invoices, not an average.** A customer with one invoice ninety days
 * late and nine current ones is a ninety-day problem, and averaging would report them as nine days late —
 * which is the number that gets them left alone.
 *
 * **A custom view rather than a `TableWidget`**, because the rows are an aggregate over a service's return
 * and not an Eloquent query. A table widget would need a query builder over `contacts` and would be a second
 * path to the same figures.
 */
class LargestDebtorsList extends Widget
{
    use WidgetBelongsToModule;

    protected string $view = 'filament.widgets.largest-debtors-list';

    /** How many. Five is the plan's figure and the point of the widget: a dashboard is not a work queue. */
    public const HOW_MANY = 5;

    /** The dashboard's period. Only the end is used: an ageing figure is an as-at, not a span. */
    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    protected static ?int $sort = DashboardWidgets::MONEY + 1;

    protected static bool $isLazy = true;

    /**
     * No polling — `docs/reports-expansion-plan.md` Phase 5.7 asks for it and Filament's default is against
     * it: `CanPoll::$pollingInterval` is `'5s'`, so every widget in this panel was re-running its aggregates
     * every five seconds, per open tab, unasked. On a dashboard of twenty-three widgets that is the cost
     * Phase 5.8's cache exists to avoid, incurred twelve times a minute instead of once a page.
     */
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // `InvoiceView`, not `ReportView`: these are invoice figures by customer, and the ageing report this
        // shares a service with is reachable by anybody who can see invoices.
        return (bool) auth()->user()?->can('InvoiceView');
    }

    /**
     * The debtors, largest first.
     *
     * @return array<int, array{contact: string, outstanding: float, days: int, invoices: int}>
     */
    public function debtors(): array
    {
        // Cached for five minutes — Phase 5.8. `outstandingReceivables()` loads every open invoice with its
        // contact to bucket it, which is the cost, and the Aged Receivables report keeps reading it uncached.
        return DashboardCache::remember(
            'largest-debtors',
            ['as_of' => $this->asOf()],
            fn (): array => $this->rank(),
        );
    }

    /**
     * The ranking, computed.
     *
     * @return array<int, array{contact: string, outstanding: float, days: int, invoices: int}>
     */
    public function rank(): array
    {
        $report = app(InvoiceService::class)->outstandingReceivables($this->asOf());

        $byContact = [];

        foreach ($report['invoices'] as $invoice) {
            $name = (string) $invoice['contact'];

            $byContact[$name] ??= ['contact' => $name, 'outstanding' => 0.0, 'days' => 0, 'invoices' => 0];
            $byContact[$name]['outstanding'] += (float) $invoice['outstanding_base'];
            $byContact[$name]['days'] = max($byContact[$name]['days'], (int) $invoice['days_overdue']);
            $byContact[$name]['invoices']++;
        }

        // Only those who actually owe something. A contact whose credit notes cancel their invoices nets to
        // nought or below and is not a debtor — and would otherwise take a place in a list of five from
        // somebody who is.
        $owing = array_filter($byContact, fn (array $row): bool => round($row['outstanding'], 2) > 0);

        usort($owing, fn (array $a, array $b): int => $b['outstanding'] <=> $a['outstanding']);

        return array_map(
            fn (array $row): array => [...$row, 'outstanding' => round($row['outstanding'], 2)],
            array_slice($owing, 0, self::HOW_MANY),
        );
    }

    /** The date the ageing is drawn to — the period's end, so a dashboard read for last quarter says who owed then. */
    public function asOf(): string
    {
        return $this->periodTo ?? now()->toDateString();
    }
}
