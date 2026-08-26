<?php

namespace App\Modules\Employees\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Joiners, leavers, headcount, turnover and how long the leavers stayed.
 *
 * `docs/reports-expansion-plan.md` Phase 3.6.
 *
 * **Two columns are read deliberately differently.** Joiners come from `date_of_joining`, because a month's
 * joiners is a fact about that month and somebody re-employed has joined again. Tenure comes from the first
 * job-history row, because `FinalSettlementBuilder` already established that continuous service is the right
 * measure — "somebody re-employed after a break has two spans and only the current one counts" — and a tenure
 * measured from the original joining date would quietly credit the company for a gap in somebody's
 * employment.
 *
 * The plan cites `employees.leaving_date`; the column is `left_on`.
 */
class HeadcountMovement extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $title = 'Headcount Movement';

    protected static ?int $navigationSort = 53;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('headcount-movement', 'Headcount Movement: Help'),
        ];
    }
}
