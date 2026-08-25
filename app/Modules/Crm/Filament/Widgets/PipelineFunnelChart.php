<?php

namespace App\Modules\Crm\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Services\PipelineReports;
use Filament\Widgets\ChartWidget;

/**
 * The pipeline by stage, as a funnel — `docs/reports-expansion-plan.md` Phase 5.3.
 *
 * **Fed by `PipelineReports::byStage()`, the service behind the Pipeline by Stage report** — and the plan
 * names this pairing explicitly. A widget counting open deals per stage itself would be a second answer to
 * "what is in the pipeline", and the two would part company the first time somebody changed what `open()`
 * means.
 *
 * **The default pipeline, as the report picks it.** `Pipeline::default()` is what `CrmReports::byStage()`
 * asks for, so widget and report describe the same pipeline. A company with none gets the report's answer
 * too: nothing, said plainly.
 *
 * **Unwindowed, deliberately — the one widget here the period does not touch.** `byStage()` takes a closing
 * window and the forecast passes one; this does not, and the service's own comment gives the reason: "a deal
 * with no expected close date is not 'closing outside the window', it is unforecastable — and it belongs in
 * the unwindowed pipeline view". A funnel is a picture of everything in play, so filtering it by the
 * dashboard's period would silently drop every deal nobody has dated.
 */
class PipelineFunnelChart extends ChartWidget
{
    use WidgetBelongsToModule;

    /**
     * Accepted and unused.
     *
     * The page hands every widget the period, and one that did not accept it would take the dashboard down
     * with an unknown-property error the moment somebody added a filter. Declaring them and ignoring them is
     * the honest version of "this is not a period question".
     */
    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    protected int|string|array $columnSpan = 'full';

    /** Sales band — see RevenueAndExpensesChart for the scheme. */
    protected static ?int $sort = 20;

    protected static bool $isLazy = true;

    public function getHeading(): ?string
    {
        return 'Pipeline by stage';
    }

    public function getDescription(): ?string
    {
        $pipeline = Pipeline::default();

        if ($pipeline === null) {
            return 'No pipeline has been set up yet.';
        }

        $rows = app(PipelineReports::class)->byStage($pipeline);

        return sprintf(
            '%s · %d open deals worth %s, weighted %s',
            $pipeline->name,
            array_sum(array_column($rows, 'count')),
            number_format(array_sum(array_column($rows, 'value')), 0),
            number_format(array_sum(array_column($rows, 'weighted')), 0),
        );
    }

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // The same gate as the pipeline reports: a chart of the same deals behind a laxer one would be a way
        // to read a report somebody may not open.
        return (bool) auth()->user()?->can('ReportView');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $pipeline = Pipeline::default();

        if ($pipeline === null) {
            return ['datasets' => [['label' => 'Weighted', 'data' => []]], 'labels' => []];
        }

        $rows = app(PipelineReports::class)->byStage($pipeline);

        return [
            'datasets' => [
                // Weighted first, because it is the figure a forecast is built from; the plain value beside
                // it is what the stage would be worth if every deal in it landed.
                ['label' => 'Weighted', 'data' => array_map(fn (array $row): float => $row['weighted'], $rows)],
                ['label' => 'Value', 'data' => array_map(fn (array $row): float => $row['value'], $rows)],
            ],
            'labels' => array_map(fn (array $row): string => (string) $row['stage']->name, $rows),
        ];
    }
}
