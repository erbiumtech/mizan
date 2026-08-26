<?php

namespace App\Modules\Crm\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What is expected to close in the month being read, weighted at each deal's stored rate.
 *
 * Open deals only. A won deal is an invoice waiting to be raised, and a forecast that counted it would
 * state the same money twice — once here and once in whatever Invoicing says. `CrmReports::forecast()`
 * carries that rule, and `CrmPipelineTest` has covered the stored-rate half of it since CRM shipped: a
 * forecast that re-read today's exchange rate would restate last quarter every morning.
 */
class SalesForecast extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $title = 'Sales Forecast';

    protected static ?int $navigationSort = 21;

    protected function reportActions(): array
    {
        // Literal, in this file, on every one of the five. HelpCoverageTest reads each page's own source
        // for `HelpAction::make('...')` — a call inherited from a shared parent is a page with no help as
        // far as that test can tell, and it is right to say so: the slug is per report, not per base class.
        return [
            HelpAction::make('crm-pipeline-reports', 'Sales Forecast: Help'),
        ];
    }
}
