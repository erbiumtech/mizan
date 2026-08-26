<?php

namespace App\Modules\Support\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What proportion of the month's tickets met their commitment, by category and by person.
 *
 * `TicketService::performance()` computed this and nothing called it — see
 * `docs/reports-expansion-plan.md` Phase 1.3, whose whole premise is that the computation is the part
 * that already exists. An SLA that is measured and never shown is a commitment nobody can be held to.
 *
 * **Reported, never enforced.** Nothing in this application refuses an action because a clock ran out,
 * and the report says so on its face — a percentage that looks like a penalty invites somebody to close
 * tickets in order to improve it.
 */
class SlaPerformance extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $title = 'SLA Performance';

    protected static ?int $navigationSort = 30;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source — a call inherited from the
        // shared parent is a page with no help as far as it can tell, and the slug is per report anyway.
        return [
            HelpAction::make('support-sla-reports', 'SLA Performance: Help'),
        ];
    }
}
