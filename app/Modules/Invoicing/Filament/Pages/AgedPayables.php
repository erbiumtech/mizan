<?php

namespace App\Modules\Invoicing\Filament\Pages;

use App\Modules\Invoicing\Models\Invoice;
use BackedEnum;

class AgedPayables extends AgedInvoices
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $title = 'Aged Payables';

    protected static ?int $navigationSort = 5;

    public function kind(): string
    {
        return Invoice::KIND_PURCHASE;
    }

    public function reportRoute(): string
    {
        return 'reports.aged-payables';
    }
}
