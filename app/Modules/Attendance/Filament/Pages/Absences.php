<?php

namespace App\Modules\Attendance\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Absences — docs/saas-launch-plan.md §14.10. */
class Absences extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-minus';

    protected static ?string $title = 'Absences';

    protected static ?int $navigationSort = 47;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source.
        return [
            HelpAction::make('absences', 'Absences: Help'),
        ];
    }
}
