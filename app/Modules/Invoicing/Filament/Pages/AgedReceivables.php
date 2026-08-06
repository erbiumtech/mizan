<?php

namespace App\Modules\Invoicing\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Invoicing\Models\Invoice;
use BackedEnum;

class AgedReceivables extends AgedInvoices
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $title = 'Aged Receivables';

    protected static ?int $navigationSort = 4;

    public function kind(): string
    {
        return Invoice::KIND_SALE;
    }

    public function reportRoute(): string
    {
        return 'reports.aged-receivables';
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('aged-receivables', 'Aged Receivables: Help'),
            ...parent::getHeaderActions(),
        ];
    }
}
