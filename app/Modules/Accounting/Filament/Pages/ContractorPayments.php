<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Services\ContractorPaymentSummary;
use App\Modules\Core\Models\FiscalYear;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * What each contractor was paid this year.
 *
 * The year-end question about people who are paid for work but are not on the payroll:
 * payroll answers it for staff and nothing answered it for them.
 */
class ContractorPayments extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.contractor-payments';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $title = 'Contractor Payments';

    protected static ?int $navigationSort = 7;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('ReportView') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['fiscal_year_id' => FiscalYear::current()?->getKey()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fiscal_year_id')
                    ->label('Fiscal year')
                    ->options(fn (): array => FiscalYear::orderByDesc('start_date')->pluck('name', 'id')->all())
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    public function getReport(): array
    {
        return app(ContractorPaymentSummary::class)->summary($this->data['fiscal_year_id'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('contractor-payments', 'Contractor Payments: Help'),
        ];
    }
}
