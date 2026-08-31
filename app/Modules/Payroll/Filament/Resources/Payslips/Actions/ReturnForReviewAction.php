<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Actions;

use App\Modules\Payroll\Models\Payslip;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * Send a corrected payslip back to the employee — `Payslip::returnForReview()`.
 *
 * **The other half of dealing with an objection, and the half that was missing.** *Close objection* says the
 * payslip was right; this says the employee was, it has been changed, and their acknowledgement is being
 * asked for again. Without it, correcting the figures left the review stuck on the old rejection —
 * `recordEmployeeReview()` refuses a second review — so the person who was right about their own pay could
 * never accept the corrected version.
 *
 * **Unlike closing, it does not wait for a reply.** There is nothing to argue about: the objection has been
 * met. What it does require is a note saying what changed, which is posted to the thread and emailed — a
 * payslip that silently returns to *pending* is one the employee has no reason to look at twice.
 *
 * Offered wherever an objection is visible — the list, the View page, the Edit page — and only to
 * `PayslipUpdate` holders, for the reason `CloseObjectionAction` gives at length.
 */
class ReturnForReviewAction
{
    public static function make(): Action
    {
        return Action::make('returnForReview')
            ->label('Send back for review')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Payslip $record): bool => ($record->isRejected() || $record->isReviewOverridden())
                && (auth()->user()?->can('PayslipUpdate') ?? false))
            ->modalHeading('Send the corrected payslip back to the employee')
            ->modalDescription(fn (Payslip $record): string => 'The employee said: "'
                .($record->employee_rejection_reason ?: 'no reason recorded')
                .'". This puts the review back to pending so they can accept the corrected figures, and holds '
                .'the salary until they do. The conversation stays on the payslip.')
            ->modalSubmitActionLabel('Send it back')
            ->schema([
                Textarea::make('note')
                    ->label('What changed')
                    ->required()
                    ->maxLength(500)
                    ->rows(3)
                    ->helperText('Emailed to the employee and added to the payslip\'s comments. "Overtime for '
                        .'the 14th added — net is now 96,400."'),
            ])
            ->action(function (array $data, Payslip $record): void {
                try {
                    $record->returnForReview($data['note']);

                    Notification::make()
                        ->title('Sent back for review; the employee has been told what changed.')
                        ->success()
                        ->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
