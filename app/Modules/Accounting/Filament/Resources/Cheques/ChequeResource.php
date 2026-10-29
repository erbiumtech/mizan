<?php

namespace App\Modules\Accounting\Filament\Resources\Cheques;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\Cheques\Pages\ListCheques;
use App\Modules\Accounting\Models\Cheque;
use App\Modules\Accounting\Services\ChequePrinter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/** Every number ever printed — docs/saas-launch-plan.md §8.6. Rows come from payments, never from a form. */
class ChequeResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Cheque::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Cheques';

    protected static ?int $navigationSort = 25;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['companyBankAccount', 'payment', 'printedBy']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cheque_no')->label('Cheque no')->searchable()->sortable(),
                TextColumn::make('companyBankAccount.title')->label('Bank account'),
                TextColumn::make('payee_name')->label('Payee')->searchable(),
                TextColumn::make('amount')->numeric(2)->alignRight(),
                TextColumn::make('cheque_date')->date('d-m-Y')->sortable(),
                IconColumn::make('crossed')->boolean(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    Cheque::STATUS_PRINTED => 'warning',
                    Cheque::STATUS_CLEARED => 'success',
                    default => 'gray',
                }),
                TextColumn::make('payment.id')->label('Payment')->prefix('#'),
                TextColumn::make('printedBy.name')->label('Printed by')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('void_reason')->label('Reason')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Cheque::STATUS_PRINTED => 'Printed (outstanding)',
                    Cheque::STATUS_CLEARED => 'Cleared',
                    Cheque::STATUS_VOID => 'Void',
                    Cheque::STATUS_STOPPED => 'Stopped',
                ]),
                SelectFilter::make('company_bank_account_id')->label('Bank account')->relationship('companyBankAccount', 'title'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('download')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Cheque $record): bool => $record->status !== Cheque::STATUS_VOID)
                    ->action(function (Cheque $record) {
                        $pdf = app(ChequePrinter::class)->renderPdf($record);

                        return response()->streamDownload(fn () => print ($pdf->raw()), $pdf->getName(), ['Content-Type' => 'application/pdf']);
                    }),
                static::voidAction(),
                static::reprintAction(),
            ]);
    }

    public static function voidAction(): Action
    {
        return Action::make('void')
            ->label('Void')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('The number stays burnt and the payment goes back to the pool. Nothing is deleted.')
            ->schema([Textarea::make('reason')->required()->rows(2)])
            ->visible(fn (Cheque $record): bool => $record->isOutstanding() && (auth()->user()?->can('ChequeUpdate') ?? false))
            ->action(function (Cheque $record, array $data): void {
                try {
                    app(ChequePrinter::class)->void($record, $data['reason'], auth()->user());
                    Notification::make()->title("Cheque {$record->cheque_no} voided.")->success()->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function reprintAction(): Action
    {
        return Action::make('reprint')
            ->label('Reprint')
            ->icon('heroicon-o-printer')
            ->requiresConfirmation()
            ->modalDescription('Voids this number and prints the next one for the same payment.')
            ->schema([Textarea::make('reason')->required()->rows(2)->placeholder('Printer jammed, wrong leaf, …')])
            ->visible(fn (Cheque $record): bool => $record->isOutstanding() && $record->payment_id && (auth()->user()?->can('ChequeUpdate') ?? false))
            ->action(function (Cheque $record, array $data) {
                try {
                    $fresh = app(ChequePrinter::class)->reprint($record, $data['reason'], auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return null;
                }

                $pdf = app(ChequePrinter::class)->renderPdf($fresh);

                return response()->streamDownload(fn () => print ($pdf->raw()), $pdf->getName(), ['Content-Type' => 'application/pdf']);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCheques::route('/'),
        ];
    }
}
