<?php

namespace App\Modules\Payroll\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
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
            HelpAction::make('tax-summary', 'Tax Summary: Help'),

            /*
             * The employee's copy of what this page shows: the certificate of tax
             * deducted they attach to their own return. Lives here rather than on the
             * employee's page because the data is payroll's — Employees importing
             * Payslip would buy back a module edge — and because this screen already
             * has the right year selected. Same source, same filter, same figures as
             * the table below it; see WithholdingCertificate for why that is the rule.
             */
            Action::make('withholdingCertificate')
                ->label('Withholding certificate')
                ->icon('heroicon-o-document-check')
                ->color('gray')
                ->schema([
                    Select::make('employee_id')
                        ->label('Employee')
                        ->required()
                        ->native(false)
                        ->searchable()
                        // Only people this year's statement actually names — a
                        // certificate of nothing deducted certifies nothing.
                        ->options(function (): array {
                            $fiscalYearId = $this->data['fiscal_year_id'] ?? null;

                            return \App\Modules\Payroll\Models\Payslip::query()
                                ->with('employee.user')
                                ->where('withholding_tax', '>', 0)
                                ->when($fiscalYearId, fn ($query) => $query->where('fiscal_year_id', $fiscalYearId))
                                ->get()
                                ->mapWithKeys(fn ($payslip): array => [
                                    $payslip->employee_id => $payslip->employee?->user?->name
                                        ?? $payslip->employee?->name
                                        ?? "Employee #{$payslip->employee_id}",
                                ])
                                ->sort()
                                ->all();
                        }),
                ])
                ->modalHeading('Certificate of tax deducted from salary')
                ->modalDescription('Rendered fresh from the payslips as they stand — the same rows and the '
                    .'same figures as this page, so the certificate can never disagree with the statement.')
                ->modalSubmitActionLabel('Download')
                ->action(function (array $data) {
                    $year = \App\Modules\Core\Models\FiscalYear::find($this->data['fiscal_year_id'] ?? null);
                    $employee = \App\Modules\Employees\Models\Employee::find($data['employee_id'] ?? null);

                    if (! $year || ! $employee) {
                        return null;
                    }

                    $certificates = app(\App\Modules\Payroll\Services\WithholdingCertificate::class);

                    if (($missing = $certificates->missingFor($employee, $year)) !== []) {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title('The certificate is missing facts it cannot invent')
                            ->body('Add '.implode('; ', $missing).'.')
                            ->persistent()
                            ->send();

                        return null;
                    }

                    $pdf = $certificates->renderPdf($employee, $year);

                    // `raw()`, not the response's content — see the payslip download
                    // for the 0-byte PDF this avoids.
                    return response()->streamDownload(
                        fn () => print ($pdf->raw()),
                        $pdf->getName(),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
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
