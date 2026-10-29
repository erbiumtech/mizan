<?php

namespace App\Modules\Advances\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Advance Defaulters — docs/saas-launch-plan.md §14.3. Drawn by AdvanceReports through ReportRenderers. */
class AdvanceDefaulters extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $title = 'Advance Defaulters';

    protected static ?int $navigationSort = 14;

    protected function reportActions(): array
    {
        return [
            HelpAction::make('advances-reports', 'Advance Defaulters: Help'),
        ];
    }
}
