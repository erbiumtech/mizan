<?php

namespace App\Modules\Crm\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Field visits — docs/saas-launch-plan.md §8.5. Drawn by FieldVisitReports through ReportRenderers. */
class UnconfirmedVisits extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $title = 'Unconfirmed Visits';

    protected static ?int $navigationSort = 25;

    protected function reportActions(): array
    {
        return [
            HelpAction::make('crm-field-visit-reports', 'Unconfirmed Visits: Help'),
        ];
    }
}
