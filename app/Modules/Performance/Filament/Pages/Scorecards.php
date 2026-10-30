<?php

namespace App\Modules\Performance\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Scorecards — docs/saas-launch-plan.md §14.10. */
class Scorecards extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $title = 'Scorecards';

    protected static ?int $navigationSort = 58;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source.
        return [
            HelpAction::make('scorecards', 'Scorecards: Help'),
        ];
    }
}
