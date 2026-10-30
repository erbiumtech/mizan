<?php

namespace App\Modules\Crm\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Weekly Sales — docs/saas-launch-plan.md §14.10. */
class WeeklySales extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $title = 'Weekly Sales';

    protected static ?int $navigationSort = 28;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source.
        return [
            HelpAction::make('weekly-sales', 'Weekly Sales: Help'),
        ];
    }
}
