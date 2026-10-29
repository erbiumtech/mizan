<?php

namespace App\Modules\Leave\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Leave Utilisation — docs/saas-launch-plan.md §14.10. */
class LeaveUtilisation extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sun';

    protected static ?string $title = 'Leave Utilisation';

    protected static ?int $navigationSort = 48;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source.
        return [
            HelpAction::make('leave-utilisation', 'Leave Utilisation: Help'),
        ];
    }
}
