<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Concerns\VoidsPaymentBatches;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\BankPaymentExportService;
use App\Modules\Accounting\Services\PaymentService;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Filament\Concerns\SelectsSalaryMonth;
use App\Modules\Payroll\Services\SalaryBankExportService;
use Carbon\Carbon;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class BankPaymentFile extends Page
{
    use BelongsToModule;

    use SelectsSalaryMonth;
    use VoidsPaymentBatches;

    protected string $view = 'filament.pages.bank-payment-file';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $title = 'Bank Payment File';

    protected static ?int $navigationSort = 5;

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
            'type' => null,
            'value_date' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->fiscalYearSelect(),
                $this->monthSelect(),
                Select::make('type')
                    ->label('Transaction type')
                    ->placeholder('All types')
                    ->options(TransactionType::where('is_active', true)->orderBy('name')->pluck('name', 'code')->all())
                    ->native(false)
                    ->live(),
                DatePicker::make('value_date')
                    ->label('Value date')
                    ->native(false)
                    ->default(now())
                    ->live(),
            ])
            ->statePath('data')
            ->columns(4);
    }

    public function getRows(): Collection
    {
        $fiscalYear = $this->fiscalYear();

        if (! $fiscalYear) {
            return new Collection;
        }

        $month = $this->data['month'] ?? null;
        $typeCode = $this->data['type'] ?? null;

        if ((! $typeCode || $typeCode === 'salary') && $month) {
            app(PaymentService::class)->generateSalaryPayments($month, $fiscalYear);
        }

        return Payment::with(['payable', 'transactionType', 'companyBankAccount.bank', 'payslip'])
            ->unreleased()
            ->when($typeCode, fn ($q) => $q->whereHas('transactionType', fn ($t) => $t->where('code', $typeCode)))
            ->when($month, fn ($q) => $this->constrainToMonth($q, $month, $fiscalYear))
            ->orderBy('transaction_type_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Narrow a payment query to the chosen salary month.
     *
     * The month scopes salaries and nothing else. A salary belongs to the month of
     * the payslip it pays, so July's file shows July's and no others.
     *
     * Rent, food, a supplier invoice — anything with no payslip behind it — is not
     * a thing that belongs to a month. It is an outstanding payable, and it stays
     * listed until it is released. Two narrower rules were tried here and both hid
     * rows people needed: requiring the value date to fall inside the month lost
     * every undated payment, and "due by the end of the month" lost the ones dated
     * for the day the run actually goes out, which is routinely the first of the
     * following month.
     *
     * The cost is that a payable entered months ahead is listed early. The value
     * date is on screen for each row and nothing leaves without the operator
     * pressing Download, so that is a visible choice rather than a silent one —
     * unlike a missing row, which is invisible by nature.
     */
    protected function constrainToMonth(Builder $query, string $month, FiscalYear $fiscalYear): Builder
    {
        return $query->where(function (Builder $q) use ($month, $fiscalYear) {
            $q->whereHas('payslip', fn (Builder $p) => $p
                ->where('month', $month)
                ->where('fiscal_year_id', $fiscalYear->id))
                ->orWhereDoesntHave('payslip');
        });
    }

    /**
     * The rows this download would actually send: everything listed, less the
     * salaries whose employee has not accepted the payslip yet.
     */
    public function getReleasableRows(): Collection
    {
        return $this->getRows()->filter(fn (Payment $payment): bool => $payment->isReleasable())->values();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->getReleasableRows()->isNotEmpty())
                ->requiresConfirmation()
                ->modalDescription(function (): string {
                    $releasable = $this->getReleasableRows()->count();
                    $held = $this->getRows()->count() - $releasable;

                    return "This releases {$releasable} payment(s) as one batch; they will not appear in the next one."
                        .($held ? " {$held} row(s) are held back — see the reason against each." : '');
                })
                ->action(function () {
                    $rows = $this->getReleasableRows();

                    if ($rows->isEmpty()) {
                        Notification::make()->danger()->title('Nothing to release.')->send();

                        return null;
                    }

                    $payments = app(PaymentService::class);
                    $reference = $payments->nextBatchReference($this->batchPrefix());

                    $csv = app(BankPaymentExportService::class)->exportPayments($rows, $this->data['value_date'] ?? null);
                    $released = $payments->release($rows, $reference);

                    Notification::make()
                        ->success()
                        ->title("Batch {$reference} released")
                        ->body($released->count().' payment(s) sent. They will not appear in the next batch.')
                        ->send();

                    $typeCode = $this->data['type'] ?? null;
                    $typeName = $typeCode ? TransactionType::byCode($typeCode)?->name : null;
                    $fileName = app(BankPaymentExportService::class)->paymentFileName($typeName);

                    return response()->streamDownload(fn () => print ($csv), $fileName, [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                    ]);
                }),

            $this->voidBatchAction(),
        ];
    }

    /**
     * Prefix a batch is numbered within: the transaction type and the month, so
     * "the second salary batch for July" reads off the reference itself.
     */
    protected function batchPrefix(): string
    {
        $type = strtoupper(substr((string) ($this->data['type'] ?? 'PMT'), 0, 3));
        $month = $this->data['month'] ?? null;
        $fiscalYear = $this->fiscalYear();

        $year = ($month && $fiscalYear)
            ? app(SalaryBankExportService::class)->yearForMonth($month, $fiscalYear)
            : now()->format('Y');

        $monthNumber = $month ? Carbon::parse("{$month} 1 {$year}")->format('m') : now()->format('m');

        return "{$type}-{$year}-{$monthNumber}";
    }
}
