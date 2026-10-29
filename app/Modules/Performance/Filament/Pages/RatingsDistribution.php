<?php

namespace App\Modules\Performance\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Ratings Distribution — docs/saas-launch-plan.md §14.10. */
class RatingsDistribution extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'Ratings Distribution';

    protected static ?int $navigationSort = 57;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source.
        return [
            HelpAction::make('ratings-distribution', 'Ratings Distribution: Help'),
        ];
    }
}
