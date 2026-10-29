<?php

namespace App\Modules\Performance\Filament\Pages;

use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/** The ledger per employee for a period — docs/saas-launch-plan.md §8.1. */
class MeritLedgerReport extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $title = 'Merit Ledger';

    protected static ?int $navigationSort = 50;
}
