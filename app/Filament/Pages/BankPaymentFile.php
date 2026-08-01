<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\SelectsSalaryMonth;
use App\Filament\Concerns\VoidsPaymentBatches;
use App\Models\FiscalYear;
use App\Models\Payment;
use App\Models\TransactionType;
use App\Services\BankPaymentExportService;
use App\Services\SalaryBankExportService;
use App\Services\PaymentService;
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
     * The month means two different things depending on the payment, and picking
     * one rule for both would drop rows silently. A salary belongs to the month
     * of the payslip it pays; anything else — a supplier, a petty cash top-up —
     * has no payslip, so it belongs to the month its value date falls in.
     *
     * Without this the page ignored the month entirely: it was used only to
     * generate the salary payments, and the list that followed was every
     * unreleased payment there had ever been, whichever month was selected.
     */
    protected function constrainToMonth(Builder $query, string $month, FiscalYear $fiscalYear): Builder
    {
        $year = app(SalaryBankExportService::class)->yearForMonth($month, $fiscalYear);
        $start = Carbon::parse("{$month} 1 {$year}")->startOfMonth();

        return $query->where(function (Builder $q) use ($month, $fiscalYear, $start) {
            $q->whereHas('payslip', fn (Builder $p) => $p
                ->where('month', $month)
                ->where('fiscal_year_id', $fiscalYear->id))
                ->orWhere(fn (Builder $other) => $other
                    ->doesntHave('payslip')
                    ->whereBetween('value_date', [$start, $start->copy()->endOfMonth()]));
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
