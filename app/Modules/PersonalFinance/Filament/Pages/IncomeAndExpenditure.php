<?php

namespace App\Modules\PersonalFinance\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Services\PersonalReportService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * What one person earned and what they spent it on, for a tax year.
 *
 * This is the screen that answers "how much did education cost me this year",
 * which is a question about a category over a period rather than a balance, so
 * it is separate from the balance sheet.
 */
class IncomeAndExpenditure extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.personal-income-expenditure';

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'Income & Expenditure';

    protected static ?int $navigationSort = 4;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('PersonalFinanceView') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'fiscal_year_id' => FiscalYear::current()?->id,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fiscal_year_id')
                    ->label('Tax year')
                    ->options(FiscalYear::orderByDesc('start_date')->pluck('name', 'id'))
                    ->live()
                    ->helperText('July to June, the same as Pakistan\'s tax year.'),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('personal-income-expenditure', 'Income & Expenditure: Help'),
        ];
    }

    /** @return array<string, mixed> */
    public function getReport(): array
    {
        return app(PersonalReportService::class)
            ->incomeAndExpenditure($this->data['fiscal_year_id'] ?? null);
    }
}
