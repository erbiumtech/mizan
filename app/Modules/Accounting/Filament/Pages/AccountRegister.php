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
     * The blank row at the foot of the register.
     *
     * Plain Livewire state rather than a second Filament form, and deliberately.
     * What makes a register a register is that the entry row IS the last row of
     * the table — same columns, same alignment, same typography — and a form
     * component brings its own label, wrapper and error markup that has to be
     * fought back out of a table cell. Six bare inputs bound to an array is less
     * machinery and a closer result.
     *
     * @var array<string, mixed>
     */
    public array $newRow = [];

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

    public function resetNewRow(): void
    {
        $this->newRow = [
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
        ];
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
     * Book the blank row and leave a fresh one ready.
     *
     * Errors land on the fields rather than in a toast: the row is still on
     * screen with what was typed in it, so "which box is wrong" is a question
     * the screen can answer directly, and a notification that disappears cannot.
     */
    public function saveNewRow(): void
    {
        if (! $this->canAddInline()) {
            abort(403);
        }

        $this->validate([
            'newRow.date' => ['required', 'date'],
            'newRow.num' => ['nullable', 'string', 'max:50'],
            'newRow.description' => ['required', 'string', 'max:255'],
            'newRow.transfer_account_id' => ['required'],
            'newRow.debit' => ['nullable', 'numeric', 'min:0'],
            'newRow.credit' => ['nullable', 'numeric', 'min:0'],
        ], attributes: [
            'newRow.date' => 'date',
            'newRow.description' => 'description',
            'newRow.transfer_account_id' => 'transfer account',
            'newRow.debit' => 'debit',
            'newRow.credit' => 'credit',
        ]);

        try {
            ['direction' => $direction, 'amount' => $amount] = static::sideAndAmount(
                $this->newRow['debit'] ?? null,
                $this->newRow['credit'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('newRow.debit', $e->getMessage());

            return;
        }

        $transfer = $this->transferOptions()->firstWhere('id', (int) $this->newRow['transfer_account_id']);

        if ($transfer === null) {
            // Not just "required": the account has to be one this register is
            // allowed to post against, and the list is scoped per register
            // account. A stale id from a switched account would otherwise reach
            // the service and fail there with a less useful message.
            $this->addError('newRow.transfer_account_id', 'Choose an account from the list.');

            return;
        }

        try {
            $entry = app(RegisterEntryService::class)->bookRow(
                $this->currentAccount(),
                Account::findOrFail($transfer['id']),
                [
                    'date' => $this->newRow['date'],
                    'description' => $this->newRow['description'],
                    'num' => $this->newRow['num'] ?: null,
                    'direction' => $direction,
                    'amount' => $amount,
                ],
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('newRow.description', $e->getMessage());

            return;
        }

        $this->justAdded = $entry->getKey();
        $this->resetNewRow();

        // Focus back to Date, so the next one can be typed without reaching for
        // the mouse. A register is used in runs of twenty, not one at a time.
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
