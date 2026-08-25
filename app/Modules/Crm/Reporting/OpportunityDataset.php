<?php

namespace App\Modules\Crm\Reporting;

use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;

/**
 * Opportunities — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **The period is the expected close date, not when the deal was created.** A pipeline report answers "what
 * might land this quarter", so the date that bounds it is the one the deal is expected to close on — which is
 * what `CrmReports` uses and what a forecast means. Bounding on creation would produce a report about sales
 * activity wearing a forecast's title.
 *
 * **Weighted amount is derived, and Phase 5.4 is why it has to be.** The weighting is the *deal's* own
 * `probability_pct`, not its stage's: the stage carries a default and a deal may depart from it, and a
 * dashboard widget that read the stage's figure weighted every deal at whatever the stage said. So the
 * derivation is `amount × probability_pct`, on the row, and a null probability weights to nothing rather than
 * to the full amount — which is the failure that version had.
 *
 * **Being derived, it cannot be summed in SQL, and a weighted forecast total is exactly what somebody wants.**
 * That is the honest limit of a builder here: `ForecastAgainstTargetOverview` and the coded pipeline reports
 * state the weighted total, because a total of a derived column is a total this registry will not push into
 * SQL. What the builder gives is the deals, each with its weighted figure, and the amount summed.
 */
class OpportunityDataset extends Dataset
{
    public static function label(): string
    {
        return 'Opportunities';
    }

    public static function description(): string
    {
        return 'One deal in a pipeline: its stage, its value and when it is expected to close.';
    }

    public static function model(): string
    {
        return Opportunity::class;
    }

    public static function permission(): string
    {
        return 'OpportunityView';
    }

    public static function periodColumn(): ?string
    {
        return 'expected_close_on';
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::make('title', 'Deal', groupable: false),
            DatasetColumn::related('pipeline', 'Pipeline', 'pipeline.name', groupBy: 'pipeline_id'),
            DatasetColumn::related('stage', 'Stage', 'stage.name', groupBy: 'pipeline_stage_id'),
            DatasetColumn::related('owner', 'Owner', 'owner.name', groupBy: 'owner_employee_id'),
            DatasetColumn::related('contact', 'Contact', 'contact.name', groupBy: 'contact_id'),
            DatasetColumn::related('project', 'Project', 'project.name', groupBy: 'project_id'),

            DatasetColumn::make('amount', 'Amount', DatasetColumn::MONEY),
            DatasetColumn::make('currency_code', 'Currency'),
            DatasetColumn::make('probability_pct', 'Probability', DatasetColumn::NUMBER),

            DatasetColumn::derived(
                'weighted_amount',
                'Weighted',
                // The deal's probability, never the stage's — see the class docblock. A null probability is
                // nought rather than the full amount: an unweighted deal is not a certain one.
                fn (Opportunity $deal): float => round((float) $deal->amount * ((int) $deal->probability_pct / 100), 2),
                DatasetColumn::MONEY,
            ),

            DatasetColumn::make('expected_close_on', 'Expected close', DatasetColumn::DATE),
            DatasetColumn::make('closed_on', 'Closed', DatasetColumn::DATE, groupable: false),
            DatasetColumn::make('outcome', 'Outcome'),
            DatasetColumn::related('lost_reason', 'Lost reason', 'lostReason.name', groupBy: 'lost_reason_id'),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::dateRange('period', 'Expected close', 'expected_close_on'),
            DatasetFilter::dateRange('closed', 'Closed', 'closed_on'),
            DatasetFilter::select('outcome', 'Outcome', 'outcome', fn (): array => [
                Opportunity::OUTCOME_WON => 'Won',
                Opportunity::OUTCOME_LOST => 'Lost',
            ]),
            DatasetFilter::select(
                'pipeline',
                'Pipeline',
                'pipeline_id',
                fn (): array => Pipeline::query()->orderBy('name')->pluck('name', 'id')->all(),
            ),
            DatasetFilter::select(
                'stage',
                'Stage',
                'pipeline_stage_id',
                // In pipeline order, then the stage's own — a stage list sorted alphabetically puts
                // "Qualified" before "Proposal" and reads as nonsense to anybody who works the pipeline.
                fn (): array => PipelineStage::query()
                    ->orderBy('pipeline_id')
                    ->orderBy('sort')
                    ->pluck('name', 'id')
                    ->all(),
            ),
            DatasetFilter::search('title', 'Deal contains', ['title']),
        ];
    }
}
