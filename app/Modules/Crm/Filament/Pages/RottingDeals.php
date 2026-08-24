<?php

namespace App\Modules\Crm\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Open deals that have stopped moving, or have nothing planned against them.
 *
 * The one report in this set that hands somebody a list to act on rather than describing what happened,
 * which is why *no open next action* counts as rotting alongside *has not moved* — and is usually the more
 * damning of the two. A deal nobody has planned anything for is not slow; it is unowned.
 *
 * Sorted by how long, longest first, by the service. The value tile is what is at risk if none of them
 * moves.
 */
class RottingDeals extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $title = 'Rotting Deals';

    protected static ?int $navigationSort = 23;

    protected function reportActions(): array
    {
        // Literal, in this file, on every one of the five. HelpCoverageTest reads each page's own source
        // for `HelpAction::make('...')` — a call inherited from a shared parent is a page with no help as
        // far as that test can tell, and it is right to say so: the slug is per report, not per base class.
        return [
            HelpAction::make('crm-pipeline-reports', 'Rotting Deals: Help'),
        ];
    }
}
