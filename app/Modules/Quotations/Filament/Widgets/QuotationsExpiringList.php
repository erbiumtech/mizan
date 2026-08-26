<?php

namespace App\Modules\Quotations\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Quotations\Services\QuotationService;
use App\Support\Reporting\DashboardWidgets;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Quotations about to lapse — `docs/reports-expansion-plan.md` Phase 5.3.
 *
 * "Quotations expiring inside 14 days."
 *
 * **Fed by `QuotationService::expiringWithin()`, which was added for this** — and adding it to the service
 * rather than querying `Quotation` here is the point. It is the mirror of `expireLapsed()`: the same three
 * conditions with the comparison the other way round. A widget with its own query would be a second
 * definition of "live but lapsing", and the first time somebody added a status to the ladder the two would
 * disagree about which quotes count.
 *
 * **Counted forward from the period's end, not from today.** The window looks ahead, so the dashboard's
 * period sets the origin rather than the span — the same reading `CashCommittedOverview` takes of the same
 * kind of question. Narrowing fourteen days to a one-month period would answer something the heading does
 * not claim.
 */
class QuotationsExpiringList extends Widget
{
    use WidgetBelongsToModule;

    protected string $view = 'filament.widgets.quotations-expiring-list';

    /** The plan's window. A fortnight is long enough to chase a quote and short enough to be a shortlist. */
    public const DAYS = 14;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    protected static ?int $sort = DashboardWidgets::SALES + 2;

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

        // `QuotationView`, not `ReportView`: these are quotations by name, and anybody who may see a
        // quotation may see that it is about to lapse.
        return (bool) auth()->user()?->can('QuotationView');
    }

    /**
     * The quotations, soonest first, with how long each has left.
     *
     * @return array<int, array{number: string, party: string, total: float, until: string, days: int}>
     */
    public function quotations(): array
    {
        $from = Carbon::parse($this->from());

        return app(QuotationService::class)
            ->expiringWithin(self::DAYS, $from->toDateString())
            ->map(fn ($quotation): array => [
                'number' => (string) $quotation->number,
                // A quotation may be to a contact or to a lead — it carries both keys and either may be
                // null. Named as one or the other so a row is never anonymous.
                'party' => (string) ($quotation->contact?->name ?? $quotation->lead?->display_label ?? 'No customer'),
                'total' => round((float) $quotation->total, 2),
                'until' => $quotation->valid_until->toDateString(),
                'days' => (int) $from->diffInDays($quotation->valid_until, false),
            ])
            ->all();
    }

    public function from(): string
    {
        return $this->periodTo ?? now()->toDateString();
    }
}
