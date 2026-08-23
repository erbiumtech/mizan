<?php

namespace App\Modules\Crm\Support;

use App\Modules\Core\Models\Company;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\SalesTarget;
use App\Modules\Crm\Services\PipelineReports;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * CRM's five reports, as the Reports explorer draws them — `docs/reports-expansion-plan.md` Phase 1.2.
 *
 * **No new business logic, which is Phase 1's whole premise.** Every figure below comes from
 * `PipelineReports`, which has carried all six aggregates — `byStage`, `forecast`, `winLoss`, `rotting`,
 * `activity`, `attainment` — with tests, and whose only consumer in `app/` was `SalesTargetResource`. Five
 * reports, one service, nothing computed here that was not computed before.
 *
 * Registered with `App\Support\Reporting\ReportRenderers` by `CrmServiceProvider`, following Payroll's
 * three: a company without CRM has five fewer reports rather than five that fail, and Accounting renders
 * none of them. See `docs/module-packaging-plan.md` §8 Group A.
 *
 * **The periods are derived rather than asked for**, deliberately. Phase 0.3 proposes a `period` filter and
 * says each piece of Phase 0 "should land with the first report that needs it" — none of these does. Each
 * takes the window its own question implies from the one date the pane already carries:
 *
 *  - *by stage* and *rotting* are snapshots of what is open now, so they take no window at all;
 *  - *forecast* looks **forward** to the end of the month being read, because a forecast of the past is a
 *    win/loss report;
 *  - *win/loss* looks **back** across the fiscal year to that date, through `ReportPeriod` rather than
 *    `startOfYear()` — this application's year runs 1 July to 30 June and the difference is six months of
 *    trading;
 *  - *attainment* is as at the date, because a target covers a period and the question is how far through
 *    it somebody is.
 */
class CrmReports
{
    use ReportShapes;

    /**
     * Count and value per stage, weighted and plain side by side.
     *
     * **Reads the default pipeline, and says which on the face of the report.** A company may have several,
     * and the honest options were a new `pipeline` filter in the pane — which would put a CRM concept in
     * Accounting's `ASKS`, the coupling Phase 1.2 has just finished removing from `supports()` — or picking
     * one and naming it. Naming it is the smaller lie: somebody with two pipelines can see immediately that
     * this is not the other one, which a silent choice would not tell them. The filter is the fix when
     * somebody actually has two, and it belongs with Phase 0.3's work rather than ahead of it.
     */
    public function byStage(string $asOf): array
    {
        $pipeline = Pipeline::default();

        if ($pipeline === null) {
            return $this->table(
                'PipelineByStage',
                'Pipeline by Stage',
                $this->subtitle('no pipeline defined'),
                ['Stage', 'Deals', 'Value', 'Weighted'],
                'minmax(0, 1fr) 7rem 10rem 10rem',
                [1, 2, 3],
                [],
                [],
                'NO PIPELINE',
                null,
                'No pipeline has been set up yet, so there are no stages to report on.',
            );
        }

        $rows = app(PipelineReports::class)->byStage($pipeline);
        $deals = array_sum(array_column($rows, 'count'));
        $value = array_sum(array_column($rows, 'value'));
        $weighted = array_sum(array_column($rows, 'weighted'));

        return $this->table(
            'PipelineByStage',
            'Pipeline by Stage',
            $this->subtitle($pipeline->name.' · open deals as at '.$asOf),
            ['Stage', 'Deals', 'Value', 'Weighted'],
            'minmax(0, 1fr) 7rem 10rem 10rem',
            [1, 2, 3],
            array_map(fn (array $row): array => [
                (string) $row['stage']->name,
                number_format($row['count']),
                number_format($row['value'], 0),
                number_format($row['weighted'], 0),
            ], $rows),
            [
                ['label' => 'IN PLAY', 'value' => round($value, 2), 'accent' => true],
                // Both, because §8's reason for computing both is that they answer different questions: the
                // weighted figure is for planning and the plain one for the conversation about what is
                // actually on the table.
                ['label' => 'WEIGHTED', 'value' => round($weighted, 2), 'accent' => false],
            ],
            mb_strtoupper($deals.' open deals in '.$pipeline->name),
            ['Total — '.$deals.' deals', number_format($deals), number_format($value, 0), number_format($weighted, 0)],
            'Nothing is open in this pipeline.',
        );
    }

    /**
     * What is expected to close in the month being read.
     *
     * **Open deals only**, which is the service's rule and worth repeating here because it is the one that
     * keeps this figure from double-counting: a won deal is an invoice waiting to be raised, and counting it
     * as forecast would state it twice against whatever Invoicing already says.
     *
     * Weighted at the **stored** rate. A report that read today's exchange rate would restate last quarter
     * every morning.
     */
    public function forecast(string $asOf): array
    {
        $from = Carbon::parse($asOf)->startOfMonth()->toDateString();
        $to = Carbon::parse($asOf)->endOfMonth()->toDateString();

        // One pipeline throughout, and the same one `byStage` reads. Both figures are filtered on it
        // rather than only the rows: a total covering every pipeline sitting above rows covering one
        // disagrees by however much is in the others, and looks like an arithmetic fault rather than a
        // scope difference.
        $pipeline = Pipeline::default();
        $reports = app(PipelineReports::class);

        $forecast = $reports->forecast($from, $to, pipelineId: $pipeline?->getKey());

        $base = Company::current()?->currency_code ?? 'PKR';
        $foreign = array_values(array_diff($forecast['currencies'], [$base]));

        // Per stage as the rows, so the total above is explicable rather than a single figure to be
        // trusted — and windowed, so the rows are this month's deals rather than the whole open pipeline
        // under this month's total.
        $stages = $pipeline === null ? [] : $reports->byStage($pipeline, closingFrom: $from, closingTo: $to);

        return $this->table(
            'SalesForecast',
            'Sales Forecast',
            $this->subtitle(($pipeline?->name === null ? '' : $pipeline->name.' · ')
                .'closing between '.$from.' and '.$to),
            ['Stage', 'Deals', 'Value', 'Weighted'],
            'minmax(0, 1fr) 7rem 10rem 10rem',
            [1, 2, 3],
            array_map(fn (array $row): array => [
                (string) $row['stage']->name,
                number_format($row['count']),
                number_format($row['value'], 0),
                number_format($row['weighted'], 0),
            ], $stages),
            [
                ['label' => 'WEIGHTED FORECAST', 'value' => round((float) $forecast['weighted'], 2), 'accent' => true],
                ['label' => 'UNWEIGHTED', 'value' => round((float) $forecast['plain'], 2), 'accent' => false],
            ],
            mb_strtoupper($forecast['count'].' deals expected to close'
                // Named rather than converted: a forecast mixing currencies at a stored rate is a figure
                // whose composition a reader has to know about.
                //
                // The test is "is any of this foreign", not "is there more than one code". A deal in the
                // company's own currency stores that code or nothing at all, so counting distinct codes
                // stayed silent on the case that matters most — one converted deal among a page of local
                // ones, which is a total carrying an assumption nobody was told about.
                .($foreign === [] ? '' : ' · '.implode(', ', $foreign))),
            // Footed, which is the assertion this report makes about itself: the stage rows and the
            // headline are the same deals through the same filter, so the column adds up to the tile.
            [
                'Total — '.$forecast['count'].' deals',
                number_format((int) $forecast['count']),
                number_format((float) $forecast['plain'], 0),
                number_format((float) $forecast['weighted'], 0),
            ],
            'No open deal is expected to close in this month.',
        );
    }

    /** Won against lost for the fiscal year to date, and what the losses were blamed on. */
    public function winLoss(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);
        $report = app(PipelineReports::class)->winLoss($period['from'], $period['to']);

        $rows = [];

        foreach ([
            'By source' => $report['by_source'],
            'By owner' => $report['by_owner'],
            'Lost reason' => $report['by_lost_reason'],
        ] as $heading => $group) {
            foreach ($group as $row) {
                $rows[] = [
                    $heading.' · '.$row['label'],
                    number_format((int) $row['won']),
                    number_format((int) $row['lost']),
                    // Null is not nought per cent — nothing closed in that bucket at all.
                    $row['rate'] === null ? '—' : number_format((float) $row['rate'], 1).'%',
                    number_format((float) $row['value'], 0),
                ];
            }
        }

        return $this->table(
            'WinLoss',
            'Win / Loss',
            $this->subtitle('closed between '.$period['from'].' and '.$period['to']),
            ['Bucket', 'Won', 'Lost', 'Win rate', 'Won value'],
            'minmax(0, 1fr) 6rem 6rem 7rem 10rem',
            [1, 2, 3, 4],
            $rows,
            [
                ['label' => 'WON', 'value' => (float) $report['won'], 'accent' => true],
                ['label' => 'LOST', 'value' => (float) $report['lost'], 'accent' => false],
            ],
            // The rate itself is the headline, and it is a rate rather than a total — so it goes in the note
            // where a figure that is not money belongs, not in a tile beside two counts.
            mb_strtoupper($report['rate'] === null
                ? 'nothing has closed in this period'
                : 'win rate '.number_format((float) $report['rate'], 1).'%'),
            null,
            'No deal has been won or lost in this period.',
        );
    }

    /**
     * Deals that have stopped moving, or have nothing planned against them.
     *
     * The one report here that hands somebody a list to act on rather than describing what happened — which
     * is why *no open next action* counts as rotting alongside *has not moved*, and is usually the more
     * damning of the two.
     */
    public function rotting(string $asOf): array
    {
        /** @var Collection<int, array{opportunity: Opportunity, reason: string, days: ?int}> $rotting */
        $rotting = app(PipelineReports::class)->rotting();

        return $this->table(
            'RottingDeals',
            'Rotting Deals',
            $this->subtitle('open deals, as at '.$asOf),
            ['Deal', 'Owner', 'Stage', 'Why', 'Days'],
            'minmax(0, 1fr) 10rem 10rem 12rem 6rem',
            [4],
            $rotting->map(fn (array $row): array => [
                (string) $row['opportunity']->title,
                (string) ($row['opportunity']->owner?->display_label ?? 'Unassigned'),
                (string) ($row['opportunity']->stage?->name ?? '—'),
                $row['reason'],
                $row['days'] === null ? '—' : number_format($row['days']),
            ])->all(),
            [[
                'label' => 'AT RISK',
                'value' => round($rotting->sum(fn (array $row): float => $row['opportunity']->baseAmount()), 2),
                'accent' => true,
            ]],
            mb_strtoupper($rotting->count().' deals need attention'),
            null,
            'Nothing is rotting — every open deal has moved recently or has something planned.',
        );
    }

    /** Each sales target against what has been achieved inside its own period. */
    public function attainment(string $asOf): array
    {
        $rows = app(PipelineReports::class)->attainment($asOf);

        return $this->table(
            'TargetAttainment',
            'Target Attainment',
            $this->subtitle('targets covering '.$asOf),
            ['Owner', 'Target', 'Period', 'Goal', 'Achieved', 'Attainment'],
            'minmax(0, 1fr) 9rem 12rem 10rem 10rem 8rem',
            [3, 4, 5],
            array_map(function (array $row): array {
                /** @var SalesTarget $target */
                $target = $row['target'];

                return [
                    (string) ($target->employee?->display_label ?? 'Unassigned'),
                    // `SalesTarget` has no label map of its own, so the enum value is humanised here rather
                    // than a `KINDS` constant being added to the model for one report's benefit.
                    (string) str($target->kind)->replace('_', ' ')->title(),
                    $target->period_start->toDateString().' → '.$target->period_end->toDateString(),
                    number_format((float) $target->target_amount, 0),
                    number_format((float) $row['achieved'], 0),
                    // Null where the goal is zero: dividing by it would be a crash, and reporting 0%
                    // against no goal would read as failure rather than as an unset target.
                    $row['attainment_pct'] === null ? '—' : number_format((float) $row['attainment_pct'], 1).'%',
                ];
            }, $rows),
            [
                ['label' => 'GOALS', 'value' => round(array_sum(array_map(
                    fn (array $row): float => (float) $row['target']->target_amount,
                    $rows,
                )), 2), 'accent' => true],
                ['label' => 'ACHIEVED', 'value' => round(array_sum(array_column($rows, 'achieved')), 2), 'accent' => false],
            ],
            mb_strtoupper(count($rows).' targets in force'),
            null,
            'No sales target covers this date.',
        );
    }
}
