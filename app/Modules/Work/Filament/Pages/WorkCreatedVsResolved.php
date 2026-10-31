<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Created vs Resolved — docs/project-management-plan.md §7.
 *
 * Whether each project is finishing work faster than it files it, week by week.
 * Drawn by `App\Modules\Work\Support\WorkReports`, registered in `WorkServiceProvider`.
 */
class WorkCreatedVsResolved extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $title = 'Created vs Resolved';

    protected static ?int $navigationSort = 62;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('work-created-vs-resolved', 'Created vs Resolved: Help'),
        ];
    }
}
