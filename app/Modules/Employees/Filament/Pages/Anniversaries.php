<?php

namespace App\Modules\Employees\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Work Anniversaries — docs/saas-launch-plan.md §14.10. */
class Anniversaries extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cake';

    protected static ?string $title = 'Work Anniversaries';

    protected static ?int $navigationSort = 54;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source.
        return [
            HelpAction::make('anniversaries', 'Work Anniversaries: Help'),
        ];
    }
}
