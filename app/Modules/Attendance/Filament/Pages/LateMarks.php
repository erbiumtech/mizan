<?php

namespace App\Modules\Attendance\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Late Marks — docs/saas-launch-plan.md §14.10. */
class LateMarks extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $title = 'Late Marks';

    protected static ?int $navigationSort = 46;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source.
        return [
            HelpAction::make('late-marks', 'Late Marks: Help'),
        ];
    }
}
