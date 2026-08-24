<?php

namespace App\Modules\Projects\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Uptime, failures and outages per environment over time.
 *
 * `docs/reports-expansion-plan.md` Phase 3.13: "checks failed and incidents per project over a period; the
 * existing widgets are point-in-time and this is the history."
 *
 * **The history is thirty days long, and saying so is the most important thing here.**
 * `ProjectEnvironmentCheck` is `Prunable` at `projects.health.retention_days`, so the checks behind an uptime
 * figure are deleted past that horizon. Offering a financial-year uptime column would present the last month
 * as though it were the last eight. Incidents are *not* pruned, so the two halves of the report deliberately
 * cover different spans and both are stated.
 *
 * Only **confirmed** incidents count as outages — `confirmed_at` exists to suppress flapping, and
 * `EnvironmentHealthOverview` already takes the same view. The finding neither existing widget can show is an
 * incident that ran while alerts were off or muted: the outage happened, the record exists, and nobody was
 * told.
 */
class EnvironmentHealth extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static ?string $title = 'Environment Health & Incidents';

    protected static ?int $navigationSort = 50;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('environment-health', 'Environment Health & Incidents: Help'),
        ];
    }
}
