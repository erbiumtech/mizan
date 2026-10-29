<?php

namespace App\Modules\Employees\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Demographics — docs/saas-launch-plan.md §14.10. */
class Demographics extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $title = 'Demographics';

    protected static ?int $navigationSort = 55;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source.
        return [
            HelpAction::make('demographics', 'Demographics: Help'),
        ];
    }
}
