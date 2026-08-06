<?php

namespace App\Modules\PersonalFinance\Filament\Resources\PersonalEntries\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\PersonalFinance\Filament\Resources\PersonalEntries\PersonalEntryResource;
use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Services\PersonalEntryService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The three verbs that make a double-entry ledger usable by somebody who does
 * not think in debits and credits. Each builds the balanced pair itself.
 */
class ListPersonalEntries extends ListRecords
{
    protected static string $resource = PersonalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('personal-transactions', 'My Transactions: Help'),
            $this->recordIncomeAction(),
            $this->recordExpenseAction(),
            $this->transferAction(),
        ];
    }

    /** @return Collection<int, PersonalAccount> */
    private function accountsOfType(string ...$types): Collection
    {
        return PersonalAccount::active()
            ->whereIn('type', $types)
            ->orderBy('code')
            ->get();
    }

    /** @return array<int, string> */
    private function options(string ...$types): array
    {
        return $this->accountsOfType(...$types)
            ->mapWithKeys(fn (PersonalAccount $a) => [$a->id => "{$a->code} — {$a->name}"])
            ->all();
    }

    /** Where money can arrive into or be paid from. */
    private function moneyAccountOptions(): array
    {
        return $this->options(PersonalAccount::TYPE_ASSET, PersonalAccount::TYPE_LIABILITY);
    }

    private function hasAccounts(): bool
    {
        return PersonalAccount::active()->exists();
    }

    private function recordIncomeAction(): Action
    {
        return Action::make('recordIncome')
            ->label('Record income')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->visible(fn (): bool => $this->hasAccounts())
            ->modalHeading('Record income')
            ->modalSubmitActionLabel('Record')
            ->schema([
                DatePicker::make('date')->label('Date')->required()->native(false)->default(now()),
                TextInput::make('amount')->label('Amount')->numeric()->required()->minValue(0.01)->prefix('PKR'),
                Select::make('category_id')
                    ->label('What kind of income')
                    ->options($this->options(PersonalAccount::TYPE_INCOME))
                    ->required()
                    ->searchable()
                    ->helperText('How this is taxed comes from the account you pick.'),
                Select::make('into_id')
                    ->label('Paid into')
                    ->options($this->moneyAccountOptions())
                    ->required()
                    ->searchable(),
                TextInput::make('description')->label('Description')->required()->maxLength(255),
            ])
            ->action(fn (array $data) => $this->book(
                fn (PersonalEntryService $service) => $service->recordIncome(
                    PersonalAccount::findOrFail($data['into_id']),
                    PersonalAccount::findOrFail($data['category_id']),
                    (float) $data['amount'],
                    ['date' => $data['date'], 'description' => $data['description']],
                ),
                'Income recorded',
            ));
    }

    private function recordExpenseAction(): Action
    {
        return Action::make('recordExpense')
            ->label('Record expense')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('warning')
            ->visible(fn (): bool => $this->hasAccounts())
            ->modalHeading('Record expense')
            ->modalSubmitActionLabel('Record')
            ->schema([
                DatePicker::make('date')->label('Date')->required()->native(false)->default(now()),
                TextInput::make('amount')->label('Amount')->numeric()->required()->minValue(0.01)->prefix('PKR'),
                Select::make('category_id')
                    ->label('What it was for')
                    ->options($this->options(PersonalAccount::TYPE_EXPENSE))
                    ->required()
                    ->searchable(),
                Select::make('from_id')
                    ->label('Paid from')
                    ->options($this->moneyAccountOptions())
                    ->required()
                    ->searchable(),
                TextInput::make('description')->label('Description')->required()->maxLength(255),
            ])
            ->action(fn (array $data) => $this->book(
                fn (PersonalEntryService $service) => $service->recordExpense(
                    PersonalAccount::findOrFail($data['category_id']),
                    PersonalAccount::findOrFail($data['from_id']),
                    (float) $data['amount'],
                    ['date' => $data['date'], 'description' => $data['description']],
                ),
                'Expense recorded',
            ));
    }

    private function transferAction(): Action
    {
        return Action::make('transfer')
            ->label('Transfer')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->visible(fn (): bool => $this->hasAccounts())
            ->modalHeading('Move money between your accounts')
            ->modalDescription('Your net worth does not change — the money is only in a different place.')
            ->modalSubmitActionLabel('Transfer')
            ->schema([
                DatePicker::make('date')->label('Date')->required()->native(false)->default(now()),
                TextInput::make('amount')->label('Amount')->numeric()->required()->minValue(0.01)->prefix('PKR'),
                Select::make('from_id')->label('From')->options($this->moneyAccountOptions())->required()->searchable(),
                Select::make('to_id')->label('To')->options($this->moneyAccountOptions())->required()->searchable(),
                TextInput::make('description')->label('Description')->required()->maxLength(255)->default('Transfer'),
            ])
            ->action(fn (array $data) => $this->book(
                fn (PersonalEntryService $service) => $service->transfer(
                    PersonalAccount::findOrFail($data['from_id']),
                    PersonalAccount::findOrFail($data['to_id']),
                    (float) $data['amount'],
                    ['date' => $data['date'], 'description' => $data['description']],
                ),
                'Transfer recorded',
            ));
    }

    /**
     * The service raises on anything it will not accept — an unbalanced pair, a
     * closed account, a transfer to the same account. Those messages are written
     * to be read, so they are shown rather than swallowed into a 500.
     */
    private function book(callable $work, string $success): void
    {
        try {
            $work(app(PersonalEntryService::class));
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
