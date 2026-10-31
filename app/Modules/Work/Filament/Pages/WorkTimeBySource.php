<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Time by Source — docs/project-management-plan.md §9 (the sixth report) and §8.8.
 *
 * Hours logged here against hours synced from a tracker, per project and person, with the days
 * on which one person has both. Drawn by `App\Modules\Work\Support\WorkReports`.
 */
class WorkTimeBySource extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $title = 'Time by Source';

    protected static ?int $navigationSort = 64;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('work-time-by-source', 'Time by Source: Help'),
        ];
    }
}
