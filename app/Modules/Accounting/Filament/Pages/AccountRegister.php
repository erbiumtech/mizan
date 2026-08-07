<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\BankTransferService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\RegisterEntryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use UnitEnum;

class AccountRegister extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.account-register';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $title = 'Account Register';

    protected static ?int $navigationSort = 6;

    public ?array $data = [];

    /**
     * The entry strip under the register.
     *
     * A real Filament form, sitting directly beneath the table with its fields
     * lined up under the columns they belong to. The first version put bare
     * inputs inside the last `<tr>`, which looked closer to GnuCash and carried
     * one flaw that mattered more than the look: a native `<select>` only
     * type-jumps on the *start* of a label, and every label here begins with the
     * account type — "Expense:5700 Rent Expense". Typing "rent" found nothing.
     * With 43 accounts in the stock chart and more in a real one, picking the
     * other side of an entry meant scrolling.
     *
     * A Filament Select searches anywhere in the label and shows a search box,
     * so "rent" finds Rent Expense. That is worth more than the fields being
     * inside the table element.
     *
     * @var array<string, mixed>|null
     */
    public ?array $newRowData = [];

    /**
     * The entry the inline row just booked, so the table can point at it.
     *
     * A row dated last week does not appear at the bottom where it was typed —
     * it sorts into its own place in the middle of the ledger. Without something
     * marking it, a correctly saved back-dated transaction looks like nothing
     * happened at all.
     */
    public ?int $justAdded = null;

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('JournalEntryView') ?? false;
    }

    public function mount(): void
    {
        $accounts = $this->registerAccounts();

        abort_if($accounts->isEmpty(), 404, 'No register accounts (postable 11xx cash/bank) found.');

        $this->form->fill([
            'account_id' => $accounts->first()->id,
            'from' => null,
            // Today, so the register agrees with the Profit & Loss and the Trial
            // Balance, which both stop there. Payments scheduled ahead are dated at
            // their value date and would otherwise appear here and nowhere else;
            // what the date excludes is reported under the table, and clearing it
            // brings them back.
            'to' => now()->toDateString(),
        ]);

        $this->resetNewRow();
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
                    // Filled with today in mount(). Not ->default(now()), which
                    // puts a Carbon in the state and carries a time of day into a
                    // date filter.
                    ->native(false)
                    ->afterOrEqual('from')
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    /**
     * Drop the end date, so entries dated later come into view.
     *
     * The default stops at today so the register agrees with the Profit & Loss and
     * the Trial Balance, which both do. That is right, and it also means a payment
     * dated a few days out is nowhere on this page — which reads as a payment that
     * was never recorded. The banner says what is missing; this is the way back in
     * without hunting for the date field.
     */
    public function includeLaterEntries(): void
    {
        $this->form->fill([
            'account_id' => $this->data['account_id'] ?? null,
            'from' => $this->data['from'] ?? null,
            'to' => null,
        ]);
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

    // ── the blank entry row ─────────────────────────────────────────────────

    /** Whoever may open the Add Transaction dialog may type in the row. */
    public function canAddInline(): bool
    {
        return (auth()->user()?->can('JournalEntryCreate') ?? false)
            && (auth()->user()?->can('RegisterPost') ?? false);
    }

    /**
     * The strip's fields, laid out under the columns they belong to.
     *
     * Twelve columns echoing the table above: Date, Num, Description, Transfer,
     * Debit, Credit, then the buttons. Labels are hidden because the table
     * header two rows up is already labelling these columns, and repeating it
     * would push the strip out of line with the thing it is meant to continue.
     */
    public function newRowForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')
                    ->label('Date')
                    ->hiddenLabel()
                    ->native(false)
                    ->required()
                    // Filled by resetNewRow(), not ->default(now()) — the same
                    // trap the To filter above carries a note about. A Carbon
                    // default puts a time of day in the state, so the entry date
                    // arrives as "2026-08-07 10:09:43" instead of a date.
                    ->columnSpan(2),

                TextInput::make('num')
                    ->label('Num')
                    ->hiddenLabel()
                    ->placeholder('Num')
                    ->maxLength(50)
                    ->columnSpan(1),

                TextInput::make('description')
                    ->label('Description')
                    ->hiddenLabel()
                    ->placeholder('Description')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(3),

                Select::make('transfer_account_id')
                    ->label('Transfer')
                    ->hiddenLabel()
                    ->placeholder('Transfer account')
                    ->options(fn (): array => $this->transferOptions()->groupBy('type')->mapWithKeys(fn ($opts, $type): array => [
                        ucfirst($type) => $opts->pluck('label', 'id')->all(),
                    ])->all())
                    // The whole reason this is a Filament field rather than a
                    // bare <select>: it searches anywhere in the label, so "rent"
                    // finds "Expense:5700 Rent Expense". A native select only
                    // jumps on the first characters, which here are the type.
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required()
                    ->columnSpan(3),

                TextInput::make('debit')
                    ->label('Debit')
                    ->hiddenLabel()
                    ->placeholder('Debit (in)')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->columnSpan(1),

                TextInput::make('credit')
                    ->label('Credit')
                    ->hiddenLabel()
                    ->placeholder('Credit (out)')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->columnSpan(1),

                Actions::make([
                    FormAction::make('book')
                        ->label('Book')
                        ->icon('heroicon-m-check')
                        ->color('success')
                        ->size('sm')
                        ->action('saveNewRow'),
                    FormAction::make('clearRow')
                        ->label('Clear')
                        ->icon('heroicon-m-x-mark')
                        ->color('gray')
                        ->size('sm')
                        ->link()
                        ->action('resetNewRow'),
                ])
                    ->columnSpan(1)
                    ->verticallyAlignCenter(),
            ])
            ->statePath('newRowData')
            ->columns(12);
    }

    public function resetNewRow(): void
    {
        $this->newRowForm->fill([
            // Today, not the last row's date. A register is usually being caught
            // up from paperwork on the desk today, and a date that quietly
            // inherits from whatever was entered last is how a whole afternoon of
            // transactions ends up on one wrong day.
            'date' => now()->toDateString(),
            'num' => null,
            'description' => null,
            'transfer_account_id' => null,
            'debit' => null,
            'credit' => null,
        ]);
    }

    /**
     * Which side an amount was typed on, and how much.
     *
     * Shared by all three ways in — the dialog, the row, and Edit — so the one
     * rule a register has (an amount goes in exactly one of the two columns)
     * cannot come to mean different things on different screens.
     *
     * @return array{direction: string, amount: float}
     *
     * @throws \InvalidArgumentException
     */
    public static function sideAndAmount(mixed $debit, mixed $credit): array
    {
        $debit = (float) ($debit ?: 0);
        $credit = (float) ($credit ?: 0);

        if (($debit > 0) === ($credit > 0)) {
            throw new \InvalidArgumentException(
                $debit > 0
                    ? 'An amount goes in Debit or in Credit, not both.'
                    : 'Enter an amount in Debit (money in) or Credit (money out).'
            );
        }

        return [
            'direction' => $debit > 0 ? 'in' : 'out',
            'amount' => $debit > 0 ? $debit : $credit,
        ];
    }

    /**
     * Book the strip and leave a fresh one ready.
     *
     * Validation is the form's own, so a missing description marks the
     * description box rather than raising a toast that disappears while what
     * was typed is still on screen. The one rule the form cannot express — an
     * amount in exactly one of two columns — is put back on a field afterwards,
     * for the same reason.
     */
    public function saveNewRow(): void
    {
        if (! $this->canAddInline()) {
            abort(403);
        }

        $data = $this->newRowForm->getState();

        try {
            ['direction' => $direction, 'amount' => $amount] = static::sideAndAmount(
                $data['debit'] ?? null,
                $data['credit'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('newRowData.debit', $e->getMessage());

            return;
        }

        $transfer = $this->transferOptions()->firstWhere('id', (int) $data['transfer_account_id']);

        if ($transfer === null) {
            // Not just "required": the list is scoped to what this register may
            // post against, and a stale id left over from switching account
            // would otherwise reach the service and fail there with a message
            // about the ledger rather than about the box that is wrong.
            $this->addError('newRowData.transfer_account_id', 'Choose an account from the list.');

            return;
        }

        try {
            $entry = app(RegisterEntryService::class)->bookRow(
                $this->currentAccount(),
                Account::findOrFail($transfer['id']),
                [
                    // Normalised, not passed through. The picker hands back
                    // "2026-08-07 10:11:00" rather than a plain date, and while
                    // entry_date casts that away, the same value is compared
                    // against the From/To filter below — where a time of day is
                    // the difference between an entry showing and not.
                    'date' => \Illuminate\Support\Carbon::parse($data['date'])->toDateString(),
                    'description' => $data['description'],
                    'num' => $data['num'] ?: null,
                    'direction' => $direction,
                    'amount' => $amount,
                ],
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('newRowData.description', $e->getMessage());

            return;
        }

        $this->justAdded = $entry->getKey();
        $this->resetNewRow();

        // Focus back to the first field, so the next one can be typed without
        // reaching for the mouse. A register is worked in runs of twenty.
        $this->dispatch('register-row-saved');

        Notification::make()
            ->success()
            ->title("Booked {$entry->entry_number}.")
            ->body($this->outsideCurrentRange($entry->entry_date?->toDateString())
                ? 'It is dated outside the range on screen, so it is not in the list below.'
                : null)
            ->send();
    }

    /**
     * Would an entry on this date be filtered out of the view it was just typed
     * into? Saving something into invisibility, with a success message, is the
     * one outcome here that reads as a bug.
     */
    private function outsideCurrentRange(?string $date): bool
    {
        if ($date === null) {
            return false;
        }

        $from = $this->data['from'] ?? null;
        $to = $this->data['to'] ?? null;

        return ($from !== null && $date < $from) || ($to !== null && $date > $to);
    }

    /**
     * Per-row edit. Mounted with ['entry' => id] from the register table.
     *
     * Restates the entry in place rather than reversing it — see
     * RegisterEntryService::updateRow() for why the register is the one place in
     * this system where a posted entry is edited, and what bounds it.
     */
    public function editRowAction(): Action
    {
        return Action::make('editRow')
            ->label('Edit transaction')
            ->icon('heroicon-m-pencil-square')
            ->iconButton()
            ->color('gray')
            ->visible(fn (): bool => (auth()->user()?->can('JournalEntryUpdate') ?? false)
                && (auth()->user()?->can('RegisterPost') ?? false))
            ->modalHeading(fn (array $arguments): string => 'Edit '.($this->rowEntry($arguments)?->entry_number ?? 'transaction'))
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (array $arguments): array {
                $entry = $this->rowEntry($arguments);

                if (! $entry) {
                    return [];
                }

                $register = $this->currentAccount();
                $own = $entry->lines->firstWhere('account_id', $register->id);
                $other = $entry->lines->first(fn ($line) => $line->account_id !== $register->id);
                $debit = (float) ($own->debit_amount ?? 0);

                return [
                    'date' => $entry->entry_date?->toDateString(),
                    'num' => $entry->reference,
                    'description' => $entry->memo,
                    'transfer_account_id' => $other?->account_id,
                    'debit' => $debit > 0 ? $debit : null,
                    'credit' => $debit > 0 ? null : (float) ($own->credit_amount ?? 0),
                ];
            })
            ->schema(fn (): array => $this->rowFields())
            ->action(function (array $arguments, array $data): void {
                $entry = $this->rowEntry($arguments);

                if (! $entry) {
                    Notification::make()->danger()->title('Transaction not found.')->send();

                    return;
                }

                try {
                    ['direction' => $direction, 'amount' => $amount] = static::sideAndAmount(
                        $data['debit'] ?? null,
                        $data['credit'] ?? null,
                    );

                    app(RegisterEntryService::class)->updateRow($entry, $this->currentAccount(), [
                        'date' => $data['date'],
                        'description' => $data['description'],
                        'num' => $data['num'] ?? null,
                        'transfer_account_id' => $data['transfer_account_id'],
                        'direction' => $direction,
                        'amount' => $amount,
                    ]);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title("{$entry->entry_number} updated.")->send();
            });
    }

    /**
     * Per-row delete. Only reaches rows the register itself booked; anything
     * owned by another document is blocked in the service.
     */
    public function deleteRowAction(): Action
    {
        return Action::make('deleteRow')
            ->label('Delete transaction')
            ->icon('heroicon-m-trash')
            ->iconButton()
            ->color('danger')
            ->visible(fn (): bool => (auth()->user()?->can('JournalEntryDelete') ?? false)
                && (auth()->user()?->can('RegisterPost') ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Delete '.($this->rowEntry($arguments)?->entry_number ?? 'transaction').'?')
            ->modalDescription('The transaction is removed and both account balances are unwound. A full copy is kept in the audit log. To keep it visible on the ledger, reverse it instead.')
            ->modalSubmitActionLabel('Delete')
            ->action(function (array $arguments): void {
                $entry = $this->rowEntry($arguments);

                if (! $entry) {
                    Notification::make()->danger()->title('Transaction not found.')->send();

                    return;
                }

                $number = $entry->entry_number;

                try {
                    app(RegisterEntryService::class)->deleteRow($entry, $this->currentAccount());
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title("{$number} deleted.")->send();
            });
    }

    /**
     * Reverse: the audit-preserving correction, and the only option for rows the
     * register may not edit (reconciled, split, or owned by another document).
     */
    public function reverseRowAction(): Action
    {
        return Action::make('reverseRow')
            ->label('Reverse transaction')
            ->icon('heroicon-m-arrow-uturn-left')
            ->iconButton()
            ->color('warning')
            ->visible(fn (): bool => auth()->user()?->can('JournalEntryReverse') ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Reverse '.($this->rowEntry($arguments)?->entry_number ?? 'transaction').'?')
            ->modalDescription('Books a mirrored entry dated today. Both rows stay on the ledger — this is the correction that leaves a trail.')
            ->modalSubmitActionLabel('Reverse')
            ->action(function (array $arguments): void {
                $entry = $this->rowEntry($arguments);

                if (! $entry) {
                    Notification::make()->danger()->title('Transaction not found.')->send();

                    return;
                }

                try {
                    $reversal = app(JournalEntryService::class)->reverse($entry, auth()->user());
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title("Reversed by {$reversal->entry_number}.")->send();
            });
    }

    protected function rowEntry(array $arguments): ?JournalEntry
    {
        return JournalEntry::with('lines')->find($arguments['entry'] ?? null);
    }

    /** Shared between Add Transaction and Edit, so the two cannot drift apart. */
    protected function rowFields(): array
    {
        return [
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
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('account-register', 'Account Register: Help'),

            // Its own action rather than a use of Add Transaction: a transfer has one
            // direction, and leaving that to whoever is typing is how the same money
            // came to be recorded two different ways.
            Action::make('transfer')
                ->label('Transfer')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('JournalEntryCreate') && auth()->user()?->can('RegisterPost'))
                ->modalDescription('Moves money between the company\'s own cash and bank accounts. Money leaving the company is a payment, not a transfer.')
                ->schema(fn (): array => [
                    Select::make('from_account_id')
                        ->label('Out of')
                        ->options(fn (): array => app(BankTransferService::class)->accounts()
                            ->mapWithKeys(fn (Account $a): array => [$a->id => $a->code.' '.$a->name])->all())
                        ->default(fn () => $this->currentAccount()->id)
                        ->required()
                        ->native(false),
                    Select::make('to_account_id')
                        ->label('Into')
                        ->options(fn (): array => app(BankTransferService::class)->accounts()
                            ->mapWithKeys(fn (Account $a): array => [$a->id => $a->code.' '.$a->name])->all())
                        ->required()
                        ->native(false)
                        ->different('from_account_id'),
                    TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                    DatePicker::make('date')->native(false)->default(now())->required(),
                    TextInput::make('reference')->label('Reference')->maxLength(50),
                    TextInput::make('note')
                        ->label('Description')
                        ->maxLength(255)
                        ->helperText('Left empty, it reads "Transfer 1100 → 1150".'),
                ])
                ->action(function (array $data): void {
                    try {
                        $entry = app(BankTransferService::class)->transfer(
                            Account::findOrFail($data['from_account_id']),
                            Account::findOrFail($data['to_account_id']),
                            (float) $data['amount'],
                            $data['date'],
                            $data['reference'] ?? null,
                            $data['note'] ?? null,
                        );
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title("Transferred — {$entry->entry_number}.")->send();
                }),

            Action::make('addTransaction')
                ->label('Add Transaction')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('JournalEntryCreate') && auth()->user()?->can('RegisterPost'))
                ->modalDescription(fn (): string => 'Enter the amount in Debit for money into '.$this->currentAccount()->name
                    .', or Credit for money out. Posts immediately as a balanced 2-line journal entry.')
                ->schema(fn (): array => $this->rowFields())
                ->action(function (array $data): void {
                    $transfer = Account::findOrFail($data['transfer_account_id']);

                    try {
                        ['direction' => $direction, 'amount' => $amount] = static::sideAndAmount(
                            $data['debit'] ?? null,
                            $data['credit'] ?? null,
                        );

                        $entry = app(RegisterEntryService::class)->bookRow($this->currentAccount(), $transfer, [
                            'date' => $data['date'],
                            'description' => $data['description'],
                            'num' => $data['num'] ?? null,
                            'direction' => $direction,
                            'amount' => $amount,
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
