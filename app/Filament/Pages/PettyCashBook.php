<?php

namespace App\Filament\Pages;

use App\Models\TransactionType;
use App\Services\PettyCashService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use UnitEnum;

class PettyCashBook extends Page
{
    protected string $view = 'filament.pages.petty-cash-book';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $title = 'Petty Cash Book';

    protected static ?int $navigationSort = 4;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('PettyCashView') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['month' => now()->format('Y-m')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('month')
                    ->label('Month')
                    ->options($this->monthOptions())
                    ->native(false)
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    protected function monthOptions(): array
    {
        $options = [];
        $cursor = now()->startOfMonth()->addMonths(1);

        for ($i = 0; $i < 30; $i++) {
            $options[$cursor->format('Y-m')] = $cursor->format('F Y');
            $cursor->subMonth();
        }

        return $options;
    }

    public function selectedMonth(): Carbon
    {
        $value = $this->data['month'] ?? now()->format('Y-m');

        return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
    }

    public function getSummary(): array
    {
        return app(PettyCashService::class)->monthSummary($this->selectedMonth());
    }

    public function categoryTypes(): Collection
    {
        return TransactionType::where('is_active', true)
            ->whereNotNull('account_id')
            ->whereNotIn('code', ['salary', 'petty-cash-replenishment'])
            ->orderBy('name')
            ->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addVoucherAction(),
            $this->topUpAction(),
            $this->replenishAction(),
        ];
    }

    protected function addVoucherAction(): Action
    {
        return Action::make('addVoucher')
            ->label('Add Voucher')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => auth()->user()?->can('PettyCashCreate') && ! $this->getSummary()['replenished'])
            ->schema([
                DatePicker::make('date')
                    ->required()
                    ->native(false)
                    ->default(now()),
                TextInput::make('details')
                    ->required()
                    ->maxLength(255),
                Select::make('transaction_type_id')
                    ->label('Category')
                    ->options(fn () => $this->categoryTypes()->pluck('name', 'id')->all())
                    ->required()
                    ->native(false),
                TextInput::make('amount')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(),
            ])
            ->action(function (array $data): void {
                try {
                    $voucher = app(PettyCashService::class)->bookVoucher($data);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                $this->data['month'] = Carbon::parse($data['date'])->format('Y-m');

                Notification::make()->success()->title("Voucher {$voucher->voucher_no} booked.")->send();
            });
    }

    protected function topUpAction(): Action
    {
        return Action::make('topUp')
            ->label('Top Up Float')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('PettyCashReplenish') && ! $this->getSummary()['replenished'])
            ->schema([
                DatePicker::make('date')
                    ->required()
                    ->native(false)
                    ->default(now()),
                TextInput::make('amount')
                    ->label('Top-up amount (direct from bank)')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(),
            ])
            ->action(function (array $data): void {
                app(PettyCashService::class)->topUp($data['date'], (float) $data['amount']);

                $this->data['month'] = Carbon::parse($data['date'])->format('Y-m');

                Notification::make()->success()->title('Float topped up.')->send();
            });
    }

    protected function replenishAction(): Action
    {
        return Action::make('replenish')
            ->label('Replenish Month')
            ->icon('heroicon-o-banknotes')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => 'Create a replenishment payment to the custodian for '
                .number_format(max(0, $this->getSummary()['float_amount'] - $this->getSummary()['closing_balance']), 2).'?')
            ->visible(function (): bool {
                $summary = $this->getSummary();

                return auth()->user()?->can('PettyCashReplenish')
                    && ! $summary['replenished']
                    && $summary['closing_balance'] < $summary['float_amount'];
            })
            ->action(function (): void {
                try {
                    $payment = app(PettyCashService::class)->replenish($this->selectedMonth());
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title("Replenishment payment #{$payment->id} for ".number_format($payment->amount, 2).' created.')
                    ->body('It will ride in the bank payment file.')
                    ->send();
            });
    }
}
