<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectEnvironmentCheck;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Average response time per environment over a window, for the project page.
 *
 * Aggregated in SQL rather than by loading rows: at a 1-minute interval over 30
 * days this table holds tens of thousands of rows per environment.
 *
 * There is deliberately no dashboard-wide version — averaging latency across
 * unrelated projects draws a line that means nothing.
 */
class ProjectHealthChart extends ChartWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public ?string $filter = '7';

    /** Injected by ViewRecord::getHeaderWidgets(). */
    public ?Project $record = null;

    public function getHeading(): ?string
    {
        return 'Environment response time';
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('ProjectView');
    }

    protected function getFilters(): ?array
    {
        return [
            '1' => 'Last 24 hours',
            '7' => '7 days',
            '30' => '30 days',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?: 7);
        $environments = $this->record?->environments ?? collect();

        if ($environments->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        // Hourly buckets for a day, daily buckets beyond that — otherwise a
        // 30-day chart carries 720 points nobody can read.
        $hourly = $days <= 1;
        $from = $hourly ? now()->subDay() : Carbon::today()->subDays($days - 1);

        $rows = ProjectEnvironmentCheck::query()
            ->whereIn('project_environment_id', $environments->pluck('id'))
            ->where('checked_at', '>=', $from)
            ->selectRaw('project_environment_id, '.$this->bucketExpression($hourly).' as bucket, AVG(latency_ms) as avg_latency')
            ->groupBy('project_environment_id', 'bucket')
            ->get();

        $buckets = $rows->pluck('bucket')->unique()->sort()->values();

        if ($buckets->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        $palette = [
            ProjectEnvironment::KIND_PROD => '#ef4444',
            ProjectEnvironment::KIND_QUAL => '#3E894A',
            ProjectEnvironment::KIND_DEV => '#94a3b8',
        ];

        $datasets = [];

        foreach ($environments as $environment) {
            $byBucket = $rows->where('project_environment_id', $environment->id)->keyBy('bucket');

            $datasets[] = [
                'label' => $environment->label(),
                'data' => $buckets->map(fn ($bucket) => $byBucket->has($bucket)
                    ? round((float) $byBucket[$bucket]->avg_latency, 1)
                    : null)->all(),
                'borderColor' => $palette[$environment->kind] ?? '#64748b',
                'spanGaps' => true,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $buckets->all(),
        ];
    }

    /**
     * Date truncation differs between drivers: strftime on sqlite (tests),
     * date_format on mysql (production).
     */
    protected function bucketExpression(bool $hourly): string
    {
        $driver = ProjectEnvironmentCheck::query()->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return $hourly
                ? "strftime('%Y-%m-%d %H:00', checked_at)"
                : "strftime('%Y-%m-%d', checked_at)";
        }

        return $hourly
            ? "DATE_FORMAT(checked_at, '%Y-%m-%d %H:00')"
            : "DATE_FORMAT(checked_at, '%Y-%m-%d')";
    }
}
