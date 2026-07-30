<?php

namespace App\Modules\Accounting\Filament\Resources\Payments\Tables;

use App\Modules\Employees\Models\Employee;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Services\PaymentDetailsExport;
use App\Modules\Accounting\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'payable' => fn ($morphTo) => $morphTo->morphWith([Employee::class => ['user']]),
            ]))
            ->columns([
                TextColumn::make('payable.name')
                    ->label('Payable')
                    ->state(fn (Payment $record): ?string => self::payableLabel($record))
                    ->searchable(false),

                TextColumn::make('transactionType.name')
                    ->label('Transaction Type')
                    ->sortable(),

                TextColumn::make('companyBankAccount.title')
                    ->label('Debit Account'),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('details')
                    ->searchable(),

                // reference — hideFromIndex parity
                TextColumn::make('reference')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('value_date')
                    ->label('Value Date')
                    ->date(),

                // Select payment_type — displayUsing resolvedPaymentType()
                TextColumn::make('payment_type')
                    ->label('Payment Type')
                    ->state(fn (Payment $record): ?string => $record->exists ? $record->resolvedPaymentType() : null),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'warning',
                        'approved' => 'info',
                        'exported' => 'success',
                        'paid' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('journalEntry.entry_number')
                    ->label('Journal Entry'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                self::exportPaymentDetailsAction(),
            ])
            ->recordActions([
                EditAction::make(),
                self::approveAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    self::approveBulkAction(),
                ]),
            ]);
    }

    /**
     * Export the currently-filtered payments to an FBR "Payment Details" XLSX.
     */
    protected static function exportPaymentDetailsAction(): Action
    {
        return Action::make('exportPaymentDetails')
            ->label('Export Payment Details')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function ($livewire): StreamedResponse {
                $query = $livewire->getFilteredTableQuery();

                $path = tempnam(sys_get_temp_dir(), 'payment-details-').'.xlsx';
                app(PaymentDetailsExport::class)->writeToFile($query, $path);

                $fileName = 'Payment Details '.now()->format('Y-m-d').'.xlsx';

                return response()->streamDownload(function () use ($path): void {
                    readfile($path);
                    @unlink($path);
                }, $fileName, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ]);
            });
    }

    protected static function payableLabel(Payment $record): ?string
    {
        $payable = $record->payable;

        if ($payable === null) {
            return null;
        }

        // Employees show "code - name"; beneficiaries show their name.
        if ($payable instanceof Employee) {
            return $payable->display_label;
        }

        return $payable->name ?? (string) $payable->getKey();
    }

    /**
     * ApprovePayment single-record action — parity with Nova ApprovePayment.
     */
    protected static function approveAction(): Action
    {
        return Action::make('approvePayment')
            ->label('Approve Payment')
            ->icon('heroicon-o-check-circle')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('PaymentUpdate') ?? false)
            ->action(function (Payment $record): void {
                try {
                    if ($record->status !== Payment::STATUS_DRAFT) {
                        Notification::make()->title('Only draft payments can be approved.')->danger()->send();

                        return;
                    }

                    app(PaymentService::class)->approve($record);
                    Notification::make()->title('Approved 1 payment(s) and booked their journal entries.')->success()->send();
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    protected static function approveBulkAction(): BulkAction
    {
        return BulkAction::make('approvePaymentBulk')
            ->label('Approve Payment')
            ->icon('heroicon-o-check-circle')
            ->requiresConfirmation()
            ->visible(fn (): bool => auth()->user()?->can('PaymentUpdate') ?? false)
            ->action(function (Collection $records): void {
                $service = app(PaymentService::class);
                $approved = 0;

                try {
                    foreach ($records as $payment) {
                        if ($payment->status !== Payment::STATUS_DRAFT) {
                            continue;
                        }

                        $service->approve($payment);
                        $approved++;
                    }

                    Notification::make()->title("Approved {$approved} payment(s) and booked their journal entries.")->success()->send();
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
