<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Actions;

use App\Modules\Payroll\Models\Payslip;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * Close an answered objection and let the salary go — `Payslip::resolveObjection()`.
 *
 * **One definition, three placements**, and that is why it is a class rather than a method on the table. It
 * belongs on the payslips list, where somebody triaging a month works; on the payslip's View page, where the
 * conversation is read; and on Edit, where the figures were just corrected. An action defined in the table
 * and copied twice is an action that will be gated three slightly different ways within a year.
 *
 * **It collects nothing.** The answer is already in the payslip's comment thread, where the employee can
 * read it and reply to it. This is the decision that follows — it marks the objection resolved and releases
 * the payment — so all it needs is a confirmation naming what is being closed.
 *
 * **Offered only once somebody has replied.** An objection closed before anybody responded is a salary
 * released over a complaint nobody engaged with; the model refuses it and this disables the button, so the
 * rule holds whether it is reached from a screen or from code.
 *
 * **Gated on `PayslipUpdate` rather than on a permission of its own**, which is the same call the invoice
 * Credit action makes and for the same reason: whoever may correct the figures on a payslip is whoever may
 * decide the figures were right all along. That is Administrator, Accountant, Manager and CEO, and not the
 * employee — who holds `PayslipView` and would otherwise be able to overrule their own objection.
 */
class CloseObjectionAction
{
    public static function make(): Action
    {
        return Action::make('overrideRejection')
            // The label carries the reason when the button is disabled. A tooltip is a hover away and a
            // disabled button with no explanation reads as a broken one — which is exactly how it read to
            // the first person who marked a comment solved and watched the review stay *Rejected*.
            ->label(fn (Payslip $record): string => $record->objectionHasReply()
                ? 'Close objection'
                : 'Close objection (reply first)')
            ->icon('heroicon-o-check-badge')
            ->color('info')
            ->visible(fn (Payslip $record): bool => $record->isRejected()
                && (auth()->user()?->can('PayslipUpdate') ?? false))
            // Disabled rather than hidden while the conversation has not happened: a button that is simply
            // absent reads as "you may not do this", and the answer here is "not yet, and here is what first".
            ->disabled(fn (Payslip $record): bool => ! $record->objectionHasReply())
            ->tooltip(fn (Payslip $record): ?string => $record->objectionHasReply()
                ? null
                : 'Reply to the objection in the payslip\'s Comments first.')
            ->requiresConfirmation()
            ->modalHeading('Close the objection and release the salary')
            ->modalDescription(fn (Payslip $record): string => 'The employee said: "'
                .($record->employee_rejection_reason ?: 'no reason recorded')
                .'". The last reply was: "'
                .(trim((string) $record->latestObjectionReply()?->body) ?: 'nothing yet')
                .'". Closing it releases the salary for payment and emails the employee. The objection stays '
                .'on the record.')
            ->modalSubmitActionLabel('Close and release')
            ->action(function (Payslip $record): void {
                try {
                    $record->resolveObjection();

                    Notification::make()
                        ->title('Objection closed; the salary can be released.')
                        ->success()
                        ->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
