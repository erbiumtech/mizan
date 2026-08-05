<?php

namespace App\Modules\Payroll\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Filament\Concerns\SelectsSalaryMonth;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\EmployeeWithholdingTaxExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class FbrTaxFile extends Page
{
    use BelongsToModule;
    use SelectsSalaryMonth;

    protected string $view = 'filament.pages.fbr-tax-file';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $title = 'FBR Tax File';

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
            'fiscal_year_id' => $this->defaultFiscalYear()?->id,
            'month' => $this->defaultMonth(),
        ]);
    }

    /**
     * The month labels count only taxed payslips — the rows this file exports —
     * so a month showing no count really will produce an empty file.
     */
    protected function monthCountQuery(FiscalYear $fiscalYear): Builder
    {
        return Payslip::where('fiscal_year_id', $fiscalYear->id)->where('withholding_tax', '>', 0);
    }

    protected function monthCountNoun(): string
    {
        return 'with tax';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->fiscalYearSelect(),
                $this->monthSelect('Tax month'),
            ])
            ->statePath('data')
            ->columns(2);
    }

    /**
     * Payslips with a withholding tax deducted for the selected month/fiscal year.
     */
    public function query(): Builder
    {
        // Resolved, not raw state: with no selection the page should still show
        // the current year rather than every year at once.
        $fiscalYearId = $this->fiscalYear()?->id;
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
