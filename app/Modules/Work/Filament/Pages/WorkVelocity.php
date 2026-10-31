<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Velocity — docs/project-management-plan.md §7.
 *
 * What each closed sprint delivered, per project — items, points and how much of it had carried in.
 * Drawn by `App\Modules\Work\Support\WorkReports`, registered in `WorkServiceProvider`.
 */
class WorkVelocity extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $title = 'Velocity';

    protected static ?int $navigationSort = 60;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('work-velocity', 'Velocity: Help'),
        ];
    }
}
