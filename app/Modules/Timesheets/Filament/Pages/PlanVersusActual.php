<?php

namespace App\Modules\Timesheets\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What each person was allocated to, against the hours they booked — employee by project.
 *
 * The report that pays for the module: allocation says 50%, timesheets say 20%, and that gap is what
 * anybody asks a timesheet system for. Each cell states both figures rather than the difference, because a
 * difference hides which of the two is the unusual one.
 *
 * The first report in this application whose columns are data rather than a fixed set, which is why it is
 * also the first to declare itself `wide` — see `ReportShapes::table()` and
 * `docs/reports-expansion-plan.md` Phase 0.2, whose `matrix` question this answers.
 */
class PlanVersusActual extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $title = 'Plan vs Actual';

    protected static ?int $navigationSort = 41;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('timesheet-reports', 'Plan vs Actual: Help'),
        ];
    }
}
