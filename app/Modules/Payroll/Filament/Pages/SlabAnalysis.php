<?php

namespace App\Modules\Payroll\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** Tax Slab Analysis — docs/saas-launch-plan.md §14.10. */
class SlabAnalysis extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $title = 'Tax Slab Analysis';

    protected static ?int $navigationSort = 36;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source.
        return [
            HelpAction::make('slab-analysis', 'Tax Slab Analysis: Help'),
        ];
    }
}
