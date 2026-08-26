<?php

namespace App\Modules\Crm\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Won against lost for the financial year to date, by source, by owner, and by what the loss was blamed on.
 *
 * The three groupings are one report rather than three because they are read against each other: a source
 * with a low win rate and an owner with a low win rate are the same rows seen from two sides, and the lost
 * reasons are what explains either.
 *
 * The year is the *financial* year, through `App\Support\Reporting\ReportPeriod`. This application's runs
 * 1 July to 30 June, and a win rate from 1 January is a different half of the year's trading reported under
 * the same name.
 */
class WinLoss extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $title = 'Win / Loss';

    protected static ?int $navigationSort = 22;

    protected function reportActions(): array
    {
        // Literal, in this file, on every one of the five. HelpCoverageTest reads each page's own source
        // for `HelpAction::make('...')` — a call inherited from a shared parent is a page with no help as
        // far as that test can tell, and it is right to say so: the slug is per report, not per base class.
        return [
            HelpAction::make('crm-pipeline-reports', 'Win / Loss: Help'),
        ];
    }
}
