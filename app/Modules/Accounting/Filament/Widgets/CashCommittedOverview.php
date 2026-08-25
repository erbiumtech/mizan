<?php

namespace App\Modules\Accounting\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Support\CashCommitments;
use App\Support\Reporting\ReportFigures;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * What is already committed to leave the bank — `docs/reports-expansion-plan.md` Phase 5.5.
 *
 * "Cash committed in the next 90 days (Phase 1.7's own figures)."
 *
 * **Fed by `App\Support\CashCommitments`, which is the registry behind the Cash Commitments report.** Phase
 * 1.7 built it as a registry precisely so a second reader would not have to know what a commitment is: a
 * scheduled entry, a beneficiary subscription, a recurring invoice, and whatever a later phase adds. This
 * widget asks the same question over the same sources and gains new kinds without being edited.
 *
 * **Ninety days *from the period's end*, not from today.** The plan's window is forward-looking, so the
 * dashboard's period sets the origin rather than the span — reading the dashboard as at 30 June answers "what
 * was committed for the ninety days after June", which is the question somebody asks of a year end. Narrowing
 * the window to a one-month period instead would answer a question the plan did not ask and the widget's
 * heading does not claim.
 *
 * **Out and in are kept apart, and there is a net.** A commitment registry carries both directions — a
 * recurring sales invoice is money coming in — and adding them would produce a figure that is neither what
 * the company owes nor what it expects. The net is stated as well, because the thing somebody looks at this
 * for is whether the next quarter clears.
 */
class CashCommittedOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    /** How far ahead. The plan's figure, and a quarter is the horizon a cash question is asked over. */
    public const DAYS = 90;

    /** The dashboard's period. Only the end is used — see the note above on the origin. */
    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    /** Money band — see RevenueAndExpensesChart for the scheme. */
    protected static ?int $sort = 12;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // The same gate as the Cash Commitments report, which is where these figures are itemised.
        return (bool) auth()->user()?->can('ReportView');
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $from = Carbon::parse($this->periodTo ?? now()->toDateString());
        $to = $from->copy()->addDays(self::DAYS);

        $out = 0.0;
        $in = 0.0;

        foreach (CashCommitments::between($from->toDateString(), $to->toDateString()) as $row) {
            $amount = round((float) ($row['amount'] ?? 0), 2);

            if (($row['direction'] ?? 'out') === 'in') {
                $in += $amount;
            } else {
                $out += $amount;
            }
        }

        $net = round($in - $out, 2);
        $window = $from->format('j M').' to '.$to->format('j M Y');

        return [
            Stat::make('Committed out', ReportFigures::money($out))
                ->description($window)
                ->color('danger'),

            Stat::make('Expected in', ReportFigures::money($in))
                ->description($window)
                ->color('success'),

            // The answer to the question the widget is opened for, rather than left as arithmetic for the
            // reader across two stats a centimetre apart.
            Stat::make('Net', ReportFigures::money($net))
                ->description($net < 0 ? 'more going out than coming in' : 'covered by what is expected')
                ->color($net < 0 ? 'warning' : 'gray'),
        ];
    }
}
