<?php

namespace App\Modules\Advances\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Advance Disbursements — docs/saas-launch-plan.md §14.3. Drawn by AdvanceReports through ReportRenderers. */
class AdvanceDisbursements extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $title = 'Advance Disbursements';

    protected static ?int $navigationSort = 15;

    protected function reportActions(): array
    {
        return [
            HelpAction::make('advances-reports', 'Advance Disbursements: Help'),
        ];
    }
}
