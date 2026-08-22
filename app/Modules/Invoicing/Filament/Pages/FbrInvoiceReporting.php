<?php

namespace App\Modules\Invoicing\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Invoicing\Services\FbrReconciliation;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Where the books and FBR disagree.
 *
 * The companion to Payroll's FbrTaxFile, and deliberately a different kind of
 * thing. That page produces a file a human uploads; this one watches an
 * integration that reports on its own. A file nobody downloads is obvious. A
 * submission nobody notices failing is not, and every other screen in this
 * application will keep showing a perfectly healthy invoice.
 *
 * Built before anything transmits, which is the point: the report that makes a
 * failure visible is worth having in place *before* the failures can happen, not
 * after somebody has gone a quarter without noticing.
 */
class FbrInvoiceReporting extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.fbr-invoice-reporting';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar — same as FbrTaxFile.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $title = 'FBR Invoice Reporting';

    protected static ?int $navigationSort = 7;

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('ReportView') ?? false;
    }

    public function reconciliation(): FbrReconciliation
    {
        return app(FbrReconciliation::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('fbr-invoice-reporting', 'FBR Invoice Reporting: Help'),
        ];
    }
}
