<?php

namespace App\Modules\Recruitment\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Applications by stage per vacancy, with acceptance, time to hire and ageing.
 *
 * `docs/reports-expansion-plan.md` Phase 3.2. Four questions on one table because they are asked together: a
 * vacancy with fifty applicants and no offers, and one with two applicants and an offer declined, are
 * different problems and neither shows up in the other's figures.
 *
 * **Nothing here reconciles.** Phase 3 is operational reporting and there is no account behind a hiring
 * funnel, so what the report owes a reader is not overstating what it knows — an acceptance rate over offers
 * *answered* rather than issued, and a dash wherever there is no denominator.
 */
class HiringFunnel extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $title = 'Hiring Funnel';

    protected static ?int $navigationSort = 49;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('hiring-funnel', 'Hiring Funnel: Help'),
        ];
    }
}
