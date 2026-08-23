<?php

namespace App\Modules\Timesheets\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Time booked per person for a month, billable against not.
 *
 * `TimesheetService::utilisationFor()` answered this for one employee and had no caller — see
 * `docs/reports-expansion-plan.md` Phase 1.4. The question people actually ask is about the team, and
 * asking it of a per-employee method meant a loop over a service that walks every day of the month per
 * person. The company-wide figure is three grouped queries instead.
 *
 * **No capacity column, deliberately.** This module refuses to state expected hours — a rule forcing
 * timesheets and attendance to reconcile produces worse data than the gap it closes — so the report states
 * billable *share* of recorded time, which needs no assumption about what a month should have held.
 */
class TimesheetUtilisation extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $title = 'Timesheet Utilisation';

    protected static ?int $navigationSort = 40;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('timesheet-reports', 'Timesheet Utilisation: Help'),
        ];
    }
}
