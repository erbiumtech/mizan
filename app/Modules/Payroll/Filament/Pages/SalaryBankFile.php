<?php

namespace App\Modules\Payroll\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Concerns\VoidsPaymentBatches;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Services\PaymentService;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\SalaryBankExportService;
use App\Modules\Payroll\Services\SalaryPaymentGenerator;
use App\Support\Banking\SelectsSalaryMonth;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use UnitEnum;

class SalaryBankFile extends Page
{
    use BelongsToModule;
    use SelectsSalaryMonth;

    /**
     * The month labels count payslips.
     *
     * Declared here rather than inherited: the trait moved to App\Support\Banking and stopped defaulting
     * to a Payslip query, because that default was the one thing tying a form control to this module. A
     * page that wants the count says so. See docs/module-packaging-plan.md §7.
     */
    protected function monthCountQuery(FiscalYear $fiscalYear): ?Builder
    {
        return Payslip::where('fiscal_year_id', $fiscalYear->id);
    }

    use VoidsPaymentBatches;

    protected string $view = 'filament.pages.salary-bank-file';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

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

    /**
     * Every payslip of the month, each carrying the state of the Payment that
     * pays it: whether it may go in this batch, and if not, why.
     *
     * Release state lives on the Payment rather than the payslip, so this page
     * and the Bank Payment File cannot disagree about what has already been sent
     * — the same salary is one row in both, and it used to be possible to export
     * it from each of them independently.
     */
    public function getPayments(): array
    {
        $fiscalYear = $this->fiscalYear();
        $month = $this->data['month'] ?? null;

        if (! $fiscalYear || ! $month) {
            return [];
        }

        // Make sure a Payment exists for every payslip, so release state has
        // somewhere to live even for a month nobody has opened before.
        app(SalaryPaymentGenerator::class)->generate($month, $fiscalYear);

        $rows = app(SalaryBankExportService::class)->paymentsForMonth($month, $fiscalYear);

        // `payable` as well as `payslip`, and the bank behind the payable: isReleasable() and
        // releaseBlockedReason() below both reach through the morph for the account details, so
        // without this the page ran two queries per row to answer whether each payment could go.
        //
        // morphWith rather than `payable.bank`, because a morphTo has to be told which relation to
        // load on which side — both an Employee and a Beneficiary have a `bank`, and Eloquent will not
        // guess that for you.
        $payments = Payment::with([
            'payslip',
            'payable' => fn (MorphTo $morph) => $morph->morphWith([
                Employee::class => ['bank', 'user'],
                Beneficiary::class => ['bank'],
            ]),
        ])
            ->whereIn('payslip_id', collect($rows)->pluck('payslip_id')->all())
            ->get()
            ->keyBy('payslip_id');

        return collect($rows)->map(function (array $row) use ($payments): array {
            $payment = $payments[$row['payslip_id']] ?? null;

            $row['payment'] = $payment;
            $row['releasable'] = $payment?->isReleasable() ?? false;
            $row['blocked_reason'] = $payment?->releaseBlockedReason();
            $row['blocked_category'] = $payment?->releaseBlockedCategory();
            $row['batch_reference'] = $payment?->batch_reference;

            return $row;
        })->all();
    }

    /** The rows this download would send: the accepted, unreleased ones. */
    public function getReleasablePayments(): array
    {
        return array_values(array_filter($this->getPayments(), fn (array $row): bool => $row['releasable']));
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('salary-bank-file', 'Salary Bank File: Help'),
            Action::make('csv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => filled($this->data['month'] ?? null) && count($this->getReleasablePayments()) > 0)
                ->requiresConfirmation()
                ->modalHeading('Release this batch')
                ->modalDescription(function (): string {
                    $releasable = count($this->getReleasablePayments());
                    $held = count($this->getPayments()) - $releasable;

                    return "This releases {$releasable} salary payment(s) as one batch; they will not appear in the "
                        .'next one.'.($held ? " {$held} are held back until the employee accepts — see the reason against each." : '');
                })
                ->action(function () {
                    $fiscalYear = $this->fiscalYear();
                    $month = $this->data['month'] ?? null;

                    if (! $fiscalYear || ! $month) {
                        return null;
                    }

                    $rows = $this->getReleasablePayments();

                    if ($rows === []) {
                        Notification::make()->danger()->title('Nothing to release — no accepted payslip is waiting.')->send();

                        return null;
                    }

                    $payments = app(PaymentService::class);
                    $reference = $payments->nextBatchReference($this->batchPrefix($month, $fiscalYear));

                    $export = app(SalaryBankExportService::class);

                    // Only the rows going out: the file the bank receives and the
                    // payments marked released have to be the same set.
                    $csv = $export->export(
                        $month,
                        $fiscalYear,
                        $this->data['value_date'] ?? null,
                        array_column($rows, 'payslip_id'),
                    );

                    $released = $payments->release(collect($rows)->pluck('payment')->filter(), $reference);

                    Notification::make()
                        ->success()
                        ->title("Batch {$reference} released")
                        ->body($released->count().' salary payment(s) sent. They will not appear in the next batch.')
                        ->send();

                    return response()->streamDownload(
                        fn () => print ($csv),
                        $export->fileName($month, $fiscalYear),
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),

            $this->voidBatchAction(),
        ];
    }

    /** SAL-2026-07, which nextBatchReference() turns into SAL-2026-07-B1, -B2 … */
    protected function batchPrefix(string $month, $fiscalYear): string
    {
        $year = app(SalaryBankExportService::class)->yearForMonth($month, $fiscalYear);
        $monthNumber = Carbon::parse("{$month} 1 {$year}")->format('m');

        return "SAL-{$year}-{$monthNumber}";
    }
}
