<?php

namespace App\Filament\Pages;

use App\Models\FiscalYear;
use App\Models\Payslip;
use App\Services\SalaryBankExportService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use UnitEnum;

class SalaryBankFile extends Page
{
    protected string $view = 'filament.pages.salary-bank-file';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $title = 'Salary Bank File';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ReportView') ?? false;
    }

    public function mount(): void
    {
        $months = $this->months();

        $this->form->fill([
            'month' => $months->last() ?? now()->format('F'),
            'value_date' => now()->toDateString(),
        ]);
    }

    public function fiscalYear(): ?FiscalYear
    {
        return FiscalYear::where('is_active', true)->first();
    }

    public function months(): Collection
    {
        $fiscalYear = $this->fiscalYear();

        if (! $fiscalYear) {
            return collect();
        }

        return Payslip::where('fiscal_year_id', $fiscalYear->id)
            ->distinct()
            ->pluck('month')
            ->sortBy(fn ($m) => Carbon::parse("{$m} 1, 2000")->month)
            ->values();
    }

    public function form(Schema $schema): Schema
    {
        $months = $this->months();

        return $schema
            ->components([
                Select::make('month')
                    ->label('Salary month')
                    ->options($months->mapWithKeys(fn ($m) => [$m => $m])->all())
                    ->native(false)
                    ->live(),
                DatePicker::make('value_date')
                    ->label('Payment/value date')
                    ->native(false)
                    ->default(now())
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
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
