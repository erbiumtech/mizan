<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * The cheque register — docs/saas-launch-plan.md §8.6: every number printed,
 * with what it was for and whether the bank has seen it yet. The unpresented
 * total is the figure the bank reconciliation needs.
 */
class ChequeRegister extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $title = 'Cheque Register';

    protected static ?int $navigationSort = 13;

    protected function reportActions(): array
    {
        return [
            HelpAction::make('cheque-register', 'Cheque Register: Help'),
        ];
    }
}
