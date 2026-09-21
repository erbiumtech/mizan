<?php

namespace App\Modules\Invoicing\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Every receipt a customer paid short against a tax deduction certificate — the customer's side of §153.
 *
 * Read from the ledger, not from the invoice events: 1260 Advance Income Tax is the asset the company claims
 * on its own return, so the figure that report has to reconcile to is that account's movement, and a list
 * built from anything else could disagree with it. Each row is one debit to 1260, with the invoice and the
 * customer resolved from the entry's source and the certificate reference from the line's own description.
 */
class TaxWithheldByCustomers extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $title = 'Tax Withheld by Customers';

    protected static ?int $navigationSort = 6;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('tax-withheld-by-customers', 'Tax Withheld by Customers: Help'),
        ];
    }
}
