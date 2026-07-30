<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\SelectsSalaryMonth;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\BankPaymentExportService;
use App\Modules\Accounting\Services\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class BankPaymentFile extends Page
{
    use BelongsToModule;

    use SelectsSalaryMonth;

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

        return Payment::with(['payable', 'transactionType', 'companyBankAccount.bank'])
            ->whereIn('status', [Payment::STATUS_DRAFT, Payment::STATUS_APPROVED])
            ->when($typeCode, fn ($q) => $q->whereHas('transactionType', fn ($t) => $t->where('code', $typeCode)))
            ->orderBy('transaction_type_id')
            ->orderBy('id')
            ->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->getRows()->isNotEmpty())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Download marks these '.$this->getRows()->count().' payments as exported. Continue?')
                ->action(function () {
                    $rows = $this->getRows();
                    $csv = app(BankPaymentExportService::class)->exportPayments($rows, $this->data['value_date'] ?? null);
                    app(PaymentService::class)->markExported($rows);

                    $typeCode = $this->data['type'] ?? null;
                    $typeName = $typeCode ? TransactionType::byCode($typeCode)?->name : null;
                    $fileName = app(BankPaymentExportService::class)->paymentFileName($typeName);

                    return response()->streamDownload(fn () => print ($csv), $fileName, [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                    ]);
                }),
        ];
    }
}
