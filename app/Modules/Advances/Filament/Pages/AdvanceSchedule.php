<?php

namespace App\Modules\Advances\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Advance Schedule — docs/saas-launch-plan.md §14.3. Drawn by AdvanceReports through ReportRenderers. */
class AdvanceSchedule extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $title = 'Advance Schedule';

    protected static ?int $navigationSort = 13;

    protected function reportActions(): array
    {
        return [
            HelpAction::make('advances-reports', 'Advance Schedule: Help'),
        ];
    }
}
