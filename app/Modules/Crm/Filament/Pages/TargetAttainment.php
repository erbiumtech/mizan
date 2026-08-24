<?php

namespace App\Modules\Crm\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Each sales target against what has been achieved inside its own period.
 *
 * Every target in force on the date, not the current month's: targets in this application carry their own
 * `period_start` and `period_end` and a quarterly one is as legitimate as a monthly one, so the date picks
 * which targets *apply* rather than which month to measure.
 *
 * A target of nought attains nothing rather than nought per cent — an unset target reported as 0% reads as
 * a person who missed, which is the opposite of what it means.
 */
class TargetAttainment extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?string $title = 'Target Attainment';

    protected static ?int $navigationSort = 24;

    protected function getHeaderActions(): array
    {
        // Literal, in this file, on every one of the five. HelpCoverageTest reads each page's own source
        // for `HelpAction::make('...')` — a call inherited from a shared parent is a page with no help as
        // far as that test can tell, and it is right to say so: the slug is per report, not per base class.
        return [
            HelpAction::make('crm-pipeline-reports', 'Target Attainment: Help'),
        ];
    }
}
