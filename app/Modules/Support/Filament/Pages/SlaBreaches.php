<?php

namespace App\Modules\Support\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * The open tickets that have missed a commitment, or whose time is already up.
 *
 * The exception list beside `SlaPerformance`'s figures, and the one of the two that is read every
 * morning rather than at a month end. It counts a ticket nobody has answered yet whose clock has already
 * run out, not only one answered late: a breach that has not finished happening is the one still worth
 * acting on.
 *
 * A breached ticket with nobody assigned to it is the worst row in the table, so *Unassigned* is stated
 * rather than left blank and counted in its own tile.
 */
class SlaBreaches extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $title = 'SLA Breaches';

    protected static ?int $navigationSort = 31;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source — a call inherited from the
        // shared parent is a page with no help as far as it can tell, and the slug is per report anyway.
        return [
            HelpAction::make('support-sla-reports', 'SLA Breaches: Help'),
        ];
    }
}
