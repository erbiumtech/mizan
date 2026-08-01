<?php

namespace App\Modules\Accounting\Filament\Concerns;

use App\Modules\Accounting\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * "Void batch" for the two bank-file pages.
 *
 * A released batch is otherwise final, which is wrong for the case it exists to
 * handle: the bank rejects the file, or the wrong month goes out, and those
 * payments have to come back into the pool. Without this the only way back was a
 * database edit.
 *
 * Lives in Accounting because it is payments it voids. The Payroll page uses it
 * too, which is a payroll -> accounting import — already the one coupling
 * KNOWN_COUPLINGS records as deliberate and guarded.
 *
 * Shared rather than written twice because both pages release into the same
 * place — a batch raised on one is visible and voidable from the other, which is
 * what you want when the same salary appears on both.
 */
trait VoidsPaymentBatches
{
    protected function voidBatchAction(): Action
    {
        return Action::make('voidBatch')
            ->label('Void a batch')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (): bool => (auth()->user()?->can('PaymentUpdate') ?? false)
                && app(PaymentService::class)->voidableBatches()->isNotEmpty())
            ->schema([
                Select::make('batch_reference')
                    ->label('Batch')
                    ->options(fn (): array => app(PaymentService::class)->voidableBatches()->all())
                    ->helperText('Its payments return to the pool and appear in the next batch. Nothing is deleted.')
                    ->native(false)
                    ->required(),
            ])
            ->requiresConfirmation()
            ->modalHeading('Void a released batch')
            ->modalDescription('Use this when a file was rejected or built by mistake. The payments go back to '
                .'where they were before the release — approved if they had been approved, otherwise draft — and '
                .'become available to the next batch.')
            ->modalSubmitActionLabel('Void batch')
            ->action(function (array $data): void {
                try {
                    $restored = app(PaymentService::class)->voidBatch($data['batch_reference']);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Batch '.$data['batch_reference'].' voided')
                    ->body($restored->count().' payment(s) are back in the pool and will appear in the next batch.')
                    ->send();
            });
    }
}
