<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\StatutoryCoverage as Coverage;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Which laws the product does something about — docs/saas-launch-plan.md §14.10.
 *
 * **A list of mechanisms, not a claim of compliance.** Every row says what the
 * software does; none says that a company is compliant, because that is a statement
 * about a company's affairs and no software can make it. The page says so at the top,
 * where somebody about to quote it in a tender will read it.
 *
 * Shown per module, so a company sees what it has rather than a brochure.
 */
class StatutoryCoverage extends Page
{
    protected string $view = 'filament.pages.statutory-coverage';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Statutory Coverage';

    protected static ?int $navigationSort = 92;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    /** @return array<int, array<string, mixed>> */
    public function entries(): array
    {
        return Coverage::all();
    }

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('statutory-coverage', 'Statutory Coverage: Help')];
    }
}
