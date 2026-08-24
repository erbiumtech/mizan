<?php

namespace App\Modules\Quotations\Support;

use App\Modules\Quotations\Models\Quotation;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Quotation conversion — `docs/reports-expansion-plan.md` Phase 3.3.
 *
 * "Issued → accepted → invoiced with win rate, plus quotes expiring inside 14 days (`valid_until`) and
 * superseded versions excluded from the rate."
 *
 * **Superseded versions are excluded, and that is the whole reason this report needs care.** A quote revised
 * three times is one opportunity, not four. Counting each version would inflate "issued" by however often
 * the company negotiates, and drive the win rate down for doing the thing that wins work — so a revision
 * makes its predecessor `superseded`, and superseded quotes are absent from every figure here.
 *
 * **Two conversions, not one.** Issued → accepted is whether the work was won; accepted → invoiced is whether
 * anybody billed for it. The second is the one nothing else in the application surfaces, and an accepted quote
 * with no invoice against it is revenue the company has agreed and not asked for.
 *
 * **Expiring-soon is a column, not a second report.** It belongs on the month whose quotes are running out,
 * which is 2.4's reason for putting the stock flags on the product rows: the row is where the reader would
 * have to go looking anyway.
 */
class QuotationReports
{
    use ReportShapes;

    /** Fourteen days, which is the plan's figure and roughly the horizon somebody can still act inside. */
    private const EXPIRING_DAYS = 14;

    public function conversion(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $period = ReportPeriod::toDate($asOf);
        $horizon = $date->copy()->addDays(self::EXPIRING_DAYS)->toDateString();

        $quotations = Quotation::query()
            ->whereDate('issue_date', '>=', $period['from'])
            ->whereDate('issue_date', '<=', $period['to'])
            // A superseded quote is an earlier draft of an opportunity that is counted once, under whichever
            // version is current. Excluded in the query rather than filtered later, so no figure below can
            // accidentally include it.
            ->where('status', '!=', Quotation::STATUS_SUPERSEDED)
            ->get();

        if ($quotations->isEmpty()) {
            return $this->emptyConversion($asOf, $period);
        }

        $byMonth = $quotations
            ->groupBy(fn (Quotation $quote): string => Carbon::parse($quote->issue_date)->format('Y-m'))
            ->sortKeys();

        $rows = [];
        $totals = ['issued' => 0, 'accepted' => 0, 'declined' => 0, 'expired' => 0, 'invoiced' => 0, 'expiring' => 0];

        foreach ($byMonth as $month => $group) {
            $accepted = $group->where('status', Quotation::STATUS_ACCEPTED);
            $declined = $group->where('status', Quotation::STATUS_DECLINED)->count();
            $expired = $group->filter(fn (Quotation $quote): bool => $quote->hasExpired($asOf))->count();
            $invoiced = $accepted->whereNotNull('invoice_id')->count();
            $expiring = $this->expiringSoon($group, $asOf, $horizon);

            $rows[] = [
                Carbon::parse($month.'-01')->format('F Y'),
                number_format($group->count()),
                number_format($accepted->count()),
                $declined > 0 ? number_format($declined) : '—',
                $expired > 0 ? number_format($expired) : '—',
                number_format($invoiced),
                $this->winRate($accepted->count(), $declined, $expired),
                $expiring > 0 ? number_format($expiring) : '—',
            ];

            $totals['issued'] += $group->count();
            $totals['accepted'] += $accepted->count();
            $totals['declined'] += $declined;
            $totals['expired'] += $expired;
            $totals['invoiced'] += $invoiced;
            $totals['expiring'] += $expiring;
        }

        $value = round($quotations->where('status', Quotation::STATUS_ACCEPTED)->sum('total'), 2);

        return $this->table(
            'QuotationConversion',
            'Quotation Conversion',
            $this->subtitle('quoted between '.$period['from'].' and '.$period['to']),
            ['Month', 'Issued', 'Accepted', 'Declined', 'Expired', 'Invoiced', 'Win rate', 'Expiring'],
            'minmax(0, 1fr) 7rem 8rem 8rem 8rem 8rem 8rem 8rem',
            [1, 2, 3, 4, 5, 6, 7],
            $rows,
            [
                ['label' => 'WON', 'value' => $value, 'accent' => true],
                ['label' => 'QUOTES ACCEPTED', 'value' => (float) $totals['accepted'], 'accent' => false],
            ],
            $this->conversionNote($totals),
            [
                'Total — '.count($rows).' months',
                number_format($totals['issued']),
                number_format($totals['accepted']),
                $totals['declined'] > 0 ? number_format($totals['declined']) : '—',
                $totals['expired'] > 0 ? number_format($totals['expired']) : '—',
                number_format($totals['invoiced']),
                $this->winRate($totals['accepted'], $totals['declined'], $totals['expired']),
                $totals['expiring'] > 0 ? number_format($totals['expiring']) : '—',
            ],
            'No quotation was issued in this period.',
        );
    }

    /**
     * Accepted as a proportion of quotes that were *decided*.
     *
     * Decided means accepted, declined or run out of time. A quote still inside its validity has not been
     * lost, and counting it against the rate would make a company that had just quoted a lot of work look as
     * though it were losing it — the same reason the hiring funnel's acceptance rate ignores unanswered
     * offers. An expired quote *is* a loss, though: it ran out without anybody saying yes.
     *
     * A dash where nothing has been decided, because a rate with no denominator is not nought per cent.
     */
    private function winRate(int $accepted, int $declined, int $expired): string
    {
        $decided = $accepted + $declined + $expired;

        return $decided === 0 ? '—' : number_format($accepted / $decided * 100, 0).'%';
    }

    /**
     * Quotes running out inside the window and still answerable.
     *
     * Only ones that are `sent` and not yet expired: a draft has not been given to anybody, and one that has
     * already lapsed is not *expiring*, it has expired. Both would otherwise be counted as work somebody
     * could still save.
     *
     * @param  Collection<int, Quotation>  $quotations
     */
    private function expiringSoon(Collection $quotations, string $asOf, string $horizon): int
    {
        return $quotations
            ->filter(fn (Quotation $quote): bool => $quote->status === Quotation::STATUS_SENT
                && $quote->valid_until !== null
                && ! $quote->hasExpired($asOf)
                && $quote->valid_until->toDateString() <= $horizon)
            ->count();
    }

    /**
     * The two conversions and the thing to act on.
     *
     * Accepted-but-not-invoiced comes first among the warnings because it is money the company has already
     * won and not asked for, which is worse than a quote about to lapse — one is a failure to bill and the
     * other is only a deadline.
     *
     * @param  array<string, int>  $totals
     */
    private function conversionNote(array $totals): string
    {
        $unbilled = $totals['accepted'] - $totals['invoiced'];

        return mb_strtoupper(implode(' · ', array_filter([
            $totals['issued'].' quotes, superseded versions excluded',
            'win rate '.$this->winRate($totals['accepted'], $totals['declined'], $totals['expired']),
            $unbilled > 0
                ? $unbilled.($unbilled === 1 ? ' accepted quote has' : ' accepted quotes have').' no invoice yet'
                : null,
            $totals['expiring'] > 0
                ? $totals['expiring'].' expiring within '.self::EXPIRING_DAYS.' days'
                : null,
        ])));
    }

    /**
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function emptyConversion(string $asOf, array $period): array
    {
        return $this->table(
            'QuotationConversion',
            'Quotation Conversion',
            $this->subtitle('quoted between '.$period['from'].' and '.$period['to']),
            ['Month', 'Issued', 'Accepted', 'Declined', 'Expired', 'Invoiced', 'Win rate', 'Expiring'],
            'minmax(0, 1fr) 7rem 8rem 8rem 8rem 8rem 8rem 8rem',
            [1, 2, 3, 4, 5, 6, 7],
            [],
            [
                ['label' => 'WON', 'value' => 0.0, 'accent' => true],
                ['label' => 'QUOTES ACCEPTED', 'value' => 0.0, 'accent' => false],
            ],
            'NO QUOTATION WAS ISSUED IN THIS PERIOD',
            null,
            'No quotation was issued in this period.',
        );
    }
}
