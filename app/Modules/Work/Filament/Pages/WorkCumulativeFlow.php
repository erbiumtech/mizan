<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Cumulative Flow — docs/project-management-plan.md §7.
 *
 * Where every item stood at the end of each week — to do, in progress, done — per project.
 * Drawn by `App\Modules\Work\Support\WorkReports`, registered in `WorkServiceProvider`.
 */
class WorkCumulativeFlow extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'Cumulative Flow';

    protected static ?int $navigationSort = 63;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('work-cumulative-flow', 'Cumulative Flow: Help'),
        ];
    }
}
