<?php

namespace App\Modules\Accounting\Filament\Resources\Beneficiaries\RelationManagers;

use App\Modules\Accounting\Models\BeneficiarySubscription;
use App\Modules\Accounting\Services\SubscriptionBillingService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * What this beneficiary is paid every month, standing.
 *
 * Here rather than in a resource of its own: a subscription has no meaning apart
 * from the beneficiary it pays, and it is read while looking at them.
 */
class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'Monthly subscriptions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('description')
                ->label('Description')
                ->required()
                ->maxLength(255)
                ->helperText('Shown on the payment and in the bank file, e.g. "House rent".'),

            TextInput::make('amount')
                ->numeric()
                ->minValue(0.01)
                ->required(),

            TextInput::make('due_day')
                ->label('Due day')
                ->numeric()
                ->minValue(1)
                ->maxValue(31)
                ->default(1)
                ->required()
                ->helperText('Day of the month the payment is dated. Short months use their last day.'),

            Select::make('transaction_type_id')
                ->label('Transaction type')
                ->relationship('transactionType', 'name')
                ->searchable()
                ->preload()
                ->helperText("Leave empty to use the beneficiary's own type."),

            Select::make('company_bank_account_id')
                ->label('Pay from')
                ->relationship('companyBankAccount', 'account_title')
                ->searchable()
                ->preload()
                ->helperText("Leave empty to use the transaction type's default account."),

            DatePicker::make('starts_on')
                ->native(false)
                ->default(now()->startOfMonth())
                ->required()
                ->helperText('The first month billed.'),

            DatePicker::make('ends_on')
                ->native(false)
                ->after('starts_on')
                ->helperText('The last month billed. Leave empty for an open-ended agreement.'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Switch off to stop billing without losing the record of it.'),

            Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('description')->searchable()->sortable(),
                TextColumn::make('amount')->money('PKR')->sortable()->alignEnd(),

                TextColumn::make('due_day')
                    ->label('Due')
                    ->formatStateUsing(fn (int $state): string => 'day '.$state)
                    ->alignCenter(),

                TextColumn::make('transactionType.name')
                    ->label('Type')
                    // The beneficiary's own type applies when this one states none,
                    // so the column says what will actually be used.
                    ->state(fn (BeneficiarySubscription $record): string => $record->transactionType?->name
                        ?? ($record->beneficiary?->transactionType?->name ?? '—'))
                    ->toggleable(),

                TextColumn::make('starts_on')->label('From')->date('M Y')->sortable()->toggleable(),
                TextColumn::make('ends_on')->label('Until')->date('M Y')->placeholder('open')->toggleable(),

                IconColumn::make('is_active')->label('Active')->boolean(),

                // Whether this month has been raised yet, which is the question
                // anyone opening this screen mid-month is actually asking.
                TextColumn::make('this_month')
                    ->label(now()->format('M'))
                    ->state(fn (BeneficiarySubscription $record): string => app(SubscriptionBillingService::class)
                        ->alreadyBilled($record, Carbon::now()->startOfMonth()) ? 'Raised' : '—')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Raised' ? 'success' : 'gray'),
            ])
            ->defaultSort('due_day')
            ->headerActions([
                CreateAction::make(),

                // For a subscription added mid-month, or a month that needs
                // catching up without waiting for the 26th. Idempotent, so it is
                // safe to press twice.
                Action::make('raiseThisMonth')
                    ->label('Raise this month')
                    ->icon('heroicon-o-calendar-days')
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => 'Raises a draft payment for every subscription running in '
                        .now()->format('F Y').' that has not been raised yet.')
                    ->visible(fn (): bool => auth()->user()?->can('PaymentCreate') ?? false)
                    ->action(function (): void {
                        try {
                            $raised = app(SubscriptionBillingService::class)->generateFor(Carbon::now());
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title($raised->isEmpty()
                                ? 'Nothing to raise — this month is already done.'
                                : $raised->count().' draft payment(s) raised')
                            ->body($raised->isEmpty() ? null : 'Review them under Payments before they go in a batch.')
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
