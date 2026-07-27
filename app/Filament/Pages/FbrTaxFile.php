<?php

namespace App\Filament\Pages;

use App\Models\FiscalYear;
use App\Models\Payslip;
use App\Services\EmployeeWithholdingTaxExport;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use UnitEnum;

class FbrTaxFile extends Page
{
    protected string $view = 'filament.pages.fbr-tax-file';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $title = 'FBR Tax File';

    protected static ?int $navigationSort = 6;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ReportView') ?? false;
    }

    public function mount(): void
    {
        $fiscalYear = $this->fiscalYear();
        $months = $this->months($fiscalYear);

        $this->form->fill([
            'fiscal_year_id' => $fiscalYear?->id,
            'month' => $months->last(),
        ]);
    }

    public function fiscalYear(): ?FiscalYear
    {
        if ($id = ($this->data['fiscal_year_id'] ?? null)) {
            return FiscalYear::find($id);
        }

        return FiscalYear::where('is_active', true)->first();
    }

    public function months(?FiscalYear $fiscalYear): BaseCollection
    {
        if (! $fiscalYear) {
            return collect();
        }

        return Payslip::where('fiscal_year_id', $fiscalYear->id)
            ->whereNotNull('month')
            ->distinct()
            ->pluck('month')
            ->sortBy(fn ($m) => Carbon::parse("{$m} 1, 2000")->month)
            ->values();
    }

    public function form(Schema $schema): Schema
    {
        $fiscalYear = $this->fiscalYear();

        return $schema
            ->components([
                Select::make('fiscal_year_id')
                    ->label('Fiscal year')
                    ->options(FiscalYear::orderByDesc('start_date')->pluck('name', 'id')->all())
                    ->native(false)
                    ->live(),
                Select::make('month')
                    ->label('Tax month')
                    ->options($this->months($fiscalYear)->mapWithKeys(fn ($m) => [$m => $m])->all())
                    ->native(false)
                    ->live(),
            ])
            ->statePath('data')
            ->columns(2);
    }

    /**
     * Payslips with a withholding tax deducted for the selected month/fiscal year.
     */
    public function query(): Builder
    {
        $fiscalYearId = $this->data['fiscal_year_id'] ?? null;
        $month = $this->data['month'] ?? null;

        return Payslip::query()
            ->with(['employee.user', 'fiscalYear'])
            ->where('withholding_tax', '>', 0)
            ->when($fiscalYearId, fn ($q) => $q->where('fiscal_year_id', $fiscalYearId))
            ->when($month, fn ($q) => $q->where('month', $month));
    }

    public function getRows(): Collection
    {
        return $this->query()->orderBy('employee_id')->orderBy('id')->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadFbrTaxFile')
                ->label('Download FBR Tax File')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->getRows()->isNotEmpty())
                ->action(function () {
                    $path = tempnam(sys_get_temp_dir(), 'fbr-tax-file-').'.xlsx';
                    app(EmployeeWithholdingTaxExport::class)->writeToFile($this->query(), $path);

                    $month = $this->data['month'] ?? 'All';
                    $fileName = "MONTHLY DETAILS {$month}.xlsx";

                    return response()->streamDownload(function () use ($path): void {
                        readfile($path);
                        @unlink($path);
                    }, $fileName, [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
                }),
        ];
    }
}
