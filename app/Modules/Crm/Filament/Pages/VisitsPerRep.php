<?php

namespace App\Modules\Crm\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Field visits — docs/saas-launch-plan.md §8.5. Drawn by FieldVisitReports through ReportRenderers. */
class VisitsPerRep extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $title = 'Visits per Rep';

    protected static ?int $navigationSort = 24;

    protected function reportActions(): array
    {
        return [
            HelpAction::make('crm-field-visit-reports', 'Visits per Rep: Help'),
        ];
    }
}
