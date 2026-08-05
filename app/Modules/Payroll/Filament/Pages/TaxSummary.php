<?php

namespace App\Modules\Payroll\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Payroll\Services\WithholdingTaxSummary;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Salary withholding tax, by employee and by month.
 *
 * The FBR file answers "what do we file this month". It could not answer "what
 * have we withheld from this person this year" — the question the employee asks and
 * the one a year-end reconciliation needs.
 */
class TaxSummary extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.tax-summary';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $title = 'Tax Summary';

    protected static ?int $navigationSort = 6;

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
        $this->form->fill([
            'fiscal_year_id' => \App\Modules\Core\Models\FiscalYear::current()?->getKey(),
            'month' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fiscal_year_id')
                    ->label('Fiscal year')
                    ->options(fn (): array => \App\Modules\Core\Models\FiscalYear::orderByDesc('start_date')
                        ->pluck('name', 'id')->all())
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live(),

                Select::make('month')
                    ->label('Month')
                    ->placeholder('The whole year')
                    ->options(array_combine(
                        $months = ['July', 'August', 'September', 'October', 'November', 'December',
                            'January', 'February', 'March', 'April', 'May', 'June'],
                        $months,
                    ))
                    ->native(false)
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    public function getReport(): array
    {
        return app(WithholdingTaxSummary::class)->summary(
            $this->data['fiscal_year_id'] ?? null,
            $this->data['month'] ?: null,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(
                    fn (): string => route('reports.tax-summary', [
                        'company' => Filament::getTenant()?->slug,
                        ...array_filter([
                            'fiscal_year_id' => $this->data['fiscal_year_id'] ?? null,
                            'month' => $this->data['month'] ?: null,
                            'format' => 'pdf',
                        ]),
                    ]),
                    shouldOpenInNewTab: true,
                ),
        ];
    }
}
