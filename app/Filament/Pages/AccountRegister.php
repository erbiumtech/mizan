<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Services\RegisterEntryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use UnitEnum;

class AccountRegister extends Page
{
    protected string $view = 'filament.pages.account-register';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $title = 'Account Register';

    protected static ?int $navigationSort = 6;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('JournalEntryView') ?? false;
    }

    public function mount(): void
    {
        $accounts = $this->registerAccounts();

        abort_if($accounts->isEmpty(), 404, 'No register accounts (postable 11xx cash/bank) found.');

        $this->form->fill([
            'account_id' => $accounts->first()->id,
            'from' => null,
            'to' => null,
        ]);
    }

    public function registerAccounts(): Collection
    {
        return app(RegisterEntryService::class)->registerAccounts();
    }

    public function currentAccount(): Account
    {
        $accounts = $this->registerAccounts();
        $id = $this->data['account_id'] ?? null;

        return $accounts->firstWhere('id', $id) ?? $accounts->first();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('account_id')
                    ->label('Account')
                    ->options($this->registerAccounts()->mapWithKeys(fn (Account $a) => [$a->id => $a->code.' '.$a->name])->all())
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live(),
                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->live(),
                DatePicker::make('to')
                    ->label('To')
                    ->native(false)
                    ->afterOrEqual('from')
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    public function getLedger(): array
    {
        return app(RegisterEntryService::class)->registerRows(
            $this->currentAccount(),
            $this->data['from'] ?? null,
            $this->data['to'] ?? null,
        );
    }

    public function transferOptions(): Collection
    {
        return app(RegisterEntryService::class)->transferOptions($this->currentAccount());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addTransaction')
                ->label('Add Transaction')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('JournalEntryCreate') && auth()->user()?->can('RegisterPost'))
                ->modalDescription(fn (): string => 'Enter the amount in Debit for money into '.$this->currentAccount()->name
                    .', or Credit for money out. Posts immediately as a balanced 2-line journal entry.')
                ->schema([
                    DatePicker::make('date')
                        ->required()
                        ->native(false)
                        ->default(now()),
                    TextInput::make('num')
                        ->label('Num')
                        ->maxLength(50),
                    TextInput::make('description')
                        ->required()
                        ->maxLength(255),
                    Select::make('transfer_account_id')
                        ->label('Transfer')
                        ->options(fn () => $this->transferOptions()->groupBy('type')->mapWithKeys(fn ($opts, $type) => [
                            ucfirst($type) => $opts->pluck('label', 'id')->all(),
                        ])->all())
                        ->required()
                        ->native(false)
                        ->searchable(),
                    TextInput::make('debit')
                        ->label('Debit (in)')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01),
                    TextInput::make('credit')
                        ->label('Credit (out)')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01),
                ])
                ->action(function (array $data): void {
                    $debit = (float) ($data['debit'] ?? 0);
                    $credit = (float) ($data['credit'] ?? 0);

                    if (($debit > 0) === ($credit > 0)) {
                        Notification::make()->danger()->title('Enter an amount in exactly one of Debit or Credit.')->send();

                        return;
                    }

                    $transfer = Account::findOrFail($data['transfer_account_id']);

                    try {
                        $entry = app(RegisterEntryService::class)->bookRow($this->currentAccount(), $transfer, [
                            'date' => $data['date'],
                            'description' => $data['description'],
                            'num' => $data['num'] ?? null,
                            'direction' => $debit > 0 ? 'in' : 'out',
                            'amount' => $debit > 0 ? $debit : $credit,
                        ]);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title("Booked {$entry->entry_number}.")->send();
                }),
        ];
    }
}
