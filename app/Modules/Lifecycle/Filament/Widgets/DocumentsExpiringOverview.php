<?php

namespace App\Modules\Lifecycle\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Lifecycle\Services\DocumentExpiryCheck;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Documents lapsing soon — `docs/reports-expansion-plan.md` Phase 5.2.
 *
 * "Documents expiring in 30 days (the same `DocumentExpiryCheck::due()` as Phase 1.5)."
 *
 * **The plan names the service, which is Phase 5's whole rule stated for one widget.** `due()` is what the
 * Documents Expiring report reads and what the scheduled check alerts on, so all three agree about which
 * documents are a problem — including the part a fresh query would get wrong: an expired document keeps being
 * reported rather than dropping out, because (the service's words) "an expired visa is not a warning that
 * stops being true".
 *
 * **Already expired is counted apart from expiring.** Both are in `due()`, and folding them together would put
 * a lapsed work permit in the same figure as one with three weeks left — one is a compliance breach today and
 * the other is a diary entry.
 *
 * **A queue, so the period does not move it.** A visa expiring next week is expiring next week whatever span
 * somebody is reading — though the period's end is passed as the date the countdown is measured from, so
 * reading the dashboard at a year end answers what was lapsing then.
 */
class DocumentsExpiringOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    /** The plan's window. */
    public const DAYS = 30;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    /** People band — see RevenueAndExpensesChart for the scheme. */
    protected static ?int $sort = 43;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return (bool) auth()->user()?->can('EmployeeDocumentView');
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $due = app(DocumentExpiryCheck::class)->due($this->periodTo);

        $expired = $due->filter(fn (array $row): bool => $row['days'] < 0)->count();
        $soon = $due->filter(fn (array $row): bool => $row['days'] >= 0 && $row['days'] <= self::DAYS)->count();

        return [
            Stat::make('Expiring in '.self::DAYS.' days', (string) $soon)
                ->description($soon === 0 ? 'nothing lapses this month' : 'visas, licences and contracts')
                ->color($soon > 0 ? 'warning' : 'gray'),

            Stat::make('Already expired', (string) $expired)
                // Kept apart from the figure above: a lapsed permit is a breach today, not a diary entry.
                ->description($expired === 0
                    ? 'nothing has lapsed'
                    : 'still reported, because an expired document stays expired')
                ->color($expired > 0 ? 'danger' : 'gray'),
        ];
    }
}
