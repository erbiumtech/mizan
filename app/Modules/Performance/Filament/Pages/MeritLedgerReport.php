<?php

namespace App\Modules\Performance\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** The ledger per employee for a period — docs/saas-launch-plan.md §8.1. */
class MeritLedgerReport extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $title = 'Merit Ledger';

    protected static ?int $navigationSort = 50;

    protected function reportActions(): array
    {
        return [HelpAction::make('merit-ledger', 'Merit Ledger: Help')];
    }
}
