<?php

namespace App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\ScheduledTransactions\ScheduledTransactionResource;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\DeferralService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListScheduledTransactions extends ListRecords
{
    protected static string $resource = ScheduledTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('scheduled-transactions', 'Scheduled Entries: Help'),
            $this->deferAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Spread an amount over months — `docs/erpnext-gap-plan.md` Phase 5.
     *
     * **Here rather than on a page of its own, because what it produces is a row in this list.** A deferral
     * *is* a scheduled entry: the arithmetic and the account pair are the only things a person cannot work
     * out in the Create form, and those are `DeferralService`'s. Its own page would have needed a manifest
     * entry, an alias, a help topic and a navigation slot to add a form that writes the record this screen
     * already shows.
     *
     * Gated on posting as well as creating, unlike Create: the initial entry — the one that takes the amount
     * out of income — is posted immediately, because a deferral left as a draft has recognised the whole
     * amount in the month it was billed, which is the thing it exists to prevent.
     */
    private function deferAction(): Action
    {
        return Action::make('defer')
            ->label('Defer an amount')
            ->icon('heroicon-o-scissors')
            ->color('gray')
            ->visible(fn (): bool => (auth()->user()?->can('JournalEntryCreate') ?? false)
                && (auth()->user()?->can('JournalEntryPost') ?? false))
            ->modalHeading('Defer revenue or a cost')
            ->modalDescription('Takes the amount out of this month and brings it back a month at a time. '
                .'One entry is posted now; the rest becomes a monthly schedule in this list.')
            ->modalSubmitActionLabel('Defer it')
            ->schema([
                Select::make('direction')
                    ->label('What is being deferred')
                    ->options([
                        'revenue' => 'Revenue billed and not yet earned',
                        'expense' => 'A cost paid and not yet incurred',
                    ])
                    ->default('revenue')
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->required(),

                TextInput::make('description')
                    ->label('What it is')
                    ->required()
                    ->maxLength(120)
                    ->helperText('Goes on the entry and on the schedule, so both are recognisable a year '
                        .'later. "Annual licence — Acme, Jul 26 to Jun 27".'),

                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->required()
                    ->minValue(0.01),

                TextInput::make('months')
                    ->label('Over how many months')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->maxValue(120)
                    ->default(12)
                    ->helperText('Whole months. A part-month share is not offered: it needs a second '
                        .'arithmetic and a note on every report saying which one was used.'),

                DatePicker::make('starts_on')
                    ->label('First month to recognise')
                    ->native(false)
                    ->required()
                    ->default(now()->addMonthNoOverflow()->startOfMonth())
                    ->helperText('Usually next month: the month being billed for is normally already '
                        .'earned. Each share posts on the last day of its month.'),

                /*
                 * Which account it comes out of, offered rather than assumed.
                 *
                 * A company with one revenue account will take the default; one billing subscriptions
                 * through a separate account would otherwise have the deferral come out of the wrong line
                 * of its own profit and loss — visible only as two revenue accounts that no longer agree
                 * with what was invoiced.
                 */
                Select::make('account_id')
                    ->label(fn (callable $get): string => $get('direction') === 'expense'
                        ? 'Which expense account'
                        : 'Which revenue account')
                    ->options(fn (callable $get): array => Account::query()
                        ->where('type', $get('direction') === 'expense' ? 'expense' : 'income')
                        ->where('allow_manual_entry', true)
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (Account $account): array => [
                            $account->getKey() => $account->code.' '.$account->name,
                        ])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $service = app(DeferralService::class);

                try {
                    $result = $data['direction'] === 'expense'
                        ? $service->deferExpense(
                            (float) $data['amount'],
                            (int) $data['months'],
                            $data['starts_on'],
                            $data['description'],
                            (int) $data['account_id'],
                        )
                        : $service->deferRevenue(
                            (float) $data['amount'],
                            (int) $data['months'],
                            $data['starts_on'],
                            $data['description'],
                            (int) $data['account_id'],
                        );
                } catch (Throwable $e) {
                    // Said rather than thrown: every refusal this service makes is a sentence somebody can
                    // act on — an amount that does not divide, a chart with no deferral account.
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(number_format($result['deferred'], 2).' deferred')
                    ->body(number_format($result['monthly'], 2).' will be recognised on the last day of each '
                        .'month. The schedule is in this list.')
                    ->send();
            });
    }
}
