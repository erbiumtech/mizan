<?php

namespace App\Modules\Invoicing\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Invoicing\Filament\Pages\AgedPayables;
use App\Modules\Invoicing\Filament\Pages\AgedReceivables;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * What is owed to us and what we owe, split by whether it is late yet.
 *
 * The dashboard could say how much is outstanding — an A/R ledger balance and a
 * count of unpaid invoices, in two separate stat rows — but not whether any of it
 * was overdue, which is the only part that prompts anyone to do something. A
 * balance of 400,000 that is all within terms and one that is all 90 days late
 * read identically.
 *
 * The buckets come from InvoiceService, the same call the Aged Receivables and
 * Aged Payables pages render, so the dashboard headline and the report a chase
 * email is written from cannot disagree.
 */
class ReceivablesPayablesOverview extends Widget
{
    use WidgetBelongsToModule;

    protected string $view = 'filament.widgets.receivables-payables';

    protected static bool $isLazy = true;

    protected int|string|array $columnSpan = 'full';

    // Ahead of the stat rows: this is the panel with something to act on.
    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return (bool) auth()->user()?->can('InvoiceView');
    }

    /**
     * Both sides, each as: total, open (within terms), overdue, and the aging
     * buckets behind the overdue figure.
     *
     * @return array<int, array<string, mixed>>
     */
    public function panels(): array
    {
        $invoices = app(InvoiceService::class);

        return [
            $this->panel(
                'Receivables',
                'Amount you are yet to receive from your customers',
                $invoices->outstandingReceivables(),
                AgedReceivables::class,
            ),
            $this->panel(
                'Payables',
                'Amount you are yet to pay to your suppliers',
                $invoices->outstandingPayables(),
                AgedPayables::class,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  class-string  $page
     * @return array<string, mixed>
     */
    protected function panel(string $title, string $blurb, array $report, string $page): array
    {
        // days_overdue is clamped at zero by the service, so it doubles as the
        // "is this late" flag: anything above zero is past its due date.
        $overdue = array_filter($report['invoices'], fn (array $row) => $row['days_overdue'] > 0);
        $open = array_filter($report['invoices'], fn (array $row) => $row['days_overdue'] <= 0);

        $overdueTotal = round(array_sum(array_column($overdue, 'outstanding')), 2);
        $openTotal = round(array_sum(array_column($open, 'outstanding')), 2);
        $total = round($openTotal + $overdueTotal, 2);

        return [
            'title' => $title,
            'blurb' => $blurb,
            'total' => $total,
            'count' => count($report['invoices']),
            'open' => $openTotal,
            'open_count' => count($open),
            'overdue' => $overdueTotal,
            'overdue_count' => count($overdue),
            // Guarded against a zero total so an empty panel renders a flat bar
            // rather than dividing by nothing.
            'open_share' => $total > 0 ? round($openTotal / $total * 100, 1) : 0.0,
            'overdue_share' => $total > 0 ? round($overdueTotal / $total * 100, 1) : 0.0,
            'buckets' => $report['buckets'],
            'report_url' => $this->reportUrl($page),
        ];
    }

    /**
     * The aged report, when this viewer can reach it.
     *
     * Null rather than a link in two cases: no permission for the report, and no
     * current tenant — its route is /admin/{tenant}/… and generating that URL
     * without one throws, which took the whole panel down rather than dropping one
     * link from it.
     *
     * @param  class-string  $page
     */
    protected function reportUrl(string $page): ?string
    {
        if (! Filament::getTenant() || ! $page::canAccess()) {
            return null;
        }

        return $page::getUrl();
    }

    /**
     * The books are kept in one currency (see docs/akaunting-gap-plan.md §5, where
     * multi-currency is deferred until a second one has to be *booked*), so this
     * matches what the other dashboard widgets print rather than introducing a
     * setting that nothing else reads.
     */
    public function currency(): string
    {
        return 'PKR';
    }

    public function hasAnything(): bool
    {
        return Invoice::whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])->exists();
    }
}
