<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Cycle & Lead Time — docs/project-management-plan.md §7.
 *
 * How long items take once started, and how long the asker waited, per project and type.
 * Drawn by `App\Modules\Work\Support\WorkReports`, registered in `WorkServiceProvider`.
 */
class WorkCycleTime extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $title = 'Cycle & Lead Time';

    protected static ?int $navigationSort = 61;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('work-cycle-time', 'Cycle & Lead Time: Help'),
        ];
    }
}
