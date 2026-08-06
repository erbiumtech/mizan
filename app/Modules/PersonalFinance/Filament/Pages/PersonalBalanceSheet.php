<?php

namespace App\Modules\PersonalFinance\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\PersonalFinance\Services\PersonalReportService;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * What one person owns, owes, and is worth.
 *
 * No date picker, unlike the company Balance Sheet. That one has to answer "as
 * at the year end" for people outside the company; this one answers "where am I
 * now", and asking somebody to choose a date before showing them their own money
 * is friction for a question nobody asked.
 */
class PersonalBalanceSheet extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.personal-balance-sheet';

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $title = 'My Balance Sheet';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('PersonalFinanceView') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('personal-balance-sheet', 'My Balance Sheet: Help'),
        ];
    }

    /** @return array<string, mixed> */
    public function getReport(): array
    {
        return app(PersonalReportService::class)->balanceSheet();
    }
}
