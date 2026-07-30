<?php

namespace App\Modules\Payroll\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Payroll\Filament\Concerns\SelectsSalaryMonth;
use App\Modules\Payroll\Services\SalaryBankExportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class SalaryBankFile extends Page
{
    use BelongsToModule;

    use SelectsSalaryMonth;

    protected string $view = 'filament.pages.salary-bank-file';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $title = 'Salary Bank File';

    protected static ?int $navigationSort = 3;

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
            'fiscal_year_id' => $this->defaultFiscalYear()?->id,
            'month' => $this->defaultMonth(),
            'value_date' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->fiscalYearSelect(),
                $this->monthSelect(),
                DatePicker::make('value_date')
                    ->label('Payment/value date')
                    ->native(false)
                    ->default(now())
                    ->live(),
            ])
            ->statePath('data')
            ->columns(4);
    }

    public function getPayments(): array
    {
        $fiscalYear = $this->fiscalYear();
        $month = $this->data['month'] ?? null;

        if (! $fiscalYear || ! $month) {
            return [];
        }

        return app(SalaryBankExportService::class)->paymentsForMonth($month, $fiscalYear);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => filled($this->data['month'] ?? null) && count($this->getPayments()) > 0)
                ->action(function () {
                    $fiscalYear = $this->fiscalYear();
                    $month = $this->data['month'] ?? null;

                    if (! $fiscalYear || ! $month) {
                        return null;
                    }

                    $export = app(SalaryBankExportService::class);
                    $csv = $export->export($month, $fiscalYear, $this->data['value_date'] ?? null);

                    return response()->streamDownload(
                        fn () => print ($csv),
                        $export->fileName($month, $fiscalYear),
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),
        ];
    }
}
