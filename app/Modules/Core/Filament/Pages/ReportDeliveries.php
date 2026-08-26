<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What went out, to whom, when, and what failed — `docs/reports-expansion-plan.md` Phase 8, item 8.
 *
 * **A report about the application rather than about the business, and it earns its place in the hub for the
 * reason the item gives:** "a scheduled report that quietly stopped arriving is worse than one that was never
 * set up: everybody assumes the silence means nothing happened". The owner is emailed after a failure; this is
 * where anybody else can look, and where a delivery that went to three of five recipients is visible at all.
 *
 * In Core because the schedules are — the hub belongs to no module and every module puts reports in it — and
 * gated on `ReportView` like every other report, which is deliberately *not* the permission for managing a
 * schedule: reading what the application sent is not the same act as choosing what it sends.
 */
class ReportDeliveries extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $title = 'Report Deliveries';

    protected static ?int $navigationSort = 95;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('report-deliveries', 'Report Deliveries: Help'),
        ];
    }
}
