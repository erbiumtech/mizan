<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Tables;

use App\Modules\Payroll\Filament\Resources\Payslips\Actions\CloseObjectionAction;
use App\Modules\Payroll\Filament\Resources\Payslips\Actions\ReturnForReviewAction;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\AttendanceFigures;
use App\Modules\Payroll\Services\PayslipDeliveryService;
use App\Modules\Payroll\Services\PayslipService;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use App\Support\Pdf\Pdf;
use App\Support\WhatsApp\PhoneNumber;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PayslipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.tables.saved-views-bar'))
            // `comments` because the review column reads the objection's thread — whether anybody has replied,
            // and what the last one said. Loaded for the page rather than asked per row: the same question
            // answered row by row is an N+1, which is what `preventLazyLoading` reported the first time.
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.user', 'comments']))
            ->columns([
                TextColumn::make('employee.employee_id')
                    ->label('Employee')
                    ->formatStateUsing(fn ($state, $record) => $record->employee?->display_label ?? $state)
                    // Resolved to employee ids first: `users` lives in the
                    // landlord database, so a whereHas through it would emit a
                    // cross-database subquery on the tenant connection.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $q): Builder => $q
                            ->where('payslips.id', 'like', "%{$search}%")
                            ->orWhereIn('employee_id', LandlordUserColumn::employeeIdsMatching($search))))
                    ->sortable(),

                TextColumn::make('month')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fiscalYear.name')
                    ->label('Fiscal Year')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('basic_wage')
                    ->label('Basic Salary')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('medical_allowance')
                    ->label('Medical Allowance')
                    ->money('PKR')
                    ->toggleable(),

                TextColumn::make('petrol_allowance')
                    ->label('Petrol Allowance')
                    ->money('PKR')
                    ->toggleable(),

                TextColumn::make('bonus')
                    ->label('Bonus')
                    ->money('PKR')
                    ->toggleable(),

                TextColumn::make('extra_work_hours')
                    ->label('Extra Work Hours')
                    ->numeric()
                    ->toggleable(),

                TextColumn::make('expense_reimbursement')
                    ->label('Expense Reimbursment')
                    ->numeric()
                    ->toggleable(),

                TextColumn::make('advances')
                    ->label('Advances')
                    ->money('PKR')
                    ->toggleable(),

                TextColumn::make('meal_deduction')
                    ->label('Meal Deduction')
                    ->money('PKR')
                    ->toggleable(),

                TextColumn::make('withholding_tax')
                    ->label('Withholding Tax')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('total_deductions')
                    ->label('Total Deductions')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('net_salary')
                    ->label('Net Salary')
                    ->money('PKR')
                    ->sortable(),

                // Whether the employee has been sent it at all, which the review
                // state cannot say: a payslip nobody sent is "pending" too.
                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->badge()
                    ->state(fn (Payslip $record): string => $record->wasSent() ? 'sent' : 'not sent')
                    ->color(fn (Payslip $record): string => $record->wasSent() ? 'success' : 'gray')
                    ->description(fn (Payslip $record): ?string => $record->sent_at?->format('d M Y H:i'))
                    ->sortable(),

                TextColumn::make('employee_review')
                    ->label('Employee Review')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'warning',
                        'accepted' => 'success',
                        'rejected' => 'danger',
                        // Answered and released, over an objection that still stands on the record. Not
                        // green: nobody accepted this payslip, and a green badge would say they had.
                        'overridden' => 'info',
                        default => 'gray',
                    })
                    /*
                     * **Why it was rejected, under the badge that says it was.**
                     *
                     * The reason has been required at the point of rejection since the action existed, and it
                     * reached the notification email, the payment row and the bank file's blocked-reason
                     * column — everywhere except the screen the payroll team actually works from. So a
                     * rejected payslip read as a red badge and a question, and the answer was in somebody's
                     * inbox.
                     *
                     * Truncated rather than wrapped: the column is one of eight on a list read across, and the
                     * whole sentence is on hover and on the payment it blocks. Sixty characters is enough for
                     * "overtime for the 14th is missing" and short enough not to reflow the row.
                     *
                     * An acknowledgement entered by somebody signed in as the employee reads exactly like the
                     * employee's own unless the list says otherwise, so that note stays and the two are shown
                     * together when both apply.
                     */
                    ->description(fn (Payslip $record): ?string => self::reviewNote($record))
                    ->tooltip(fn (Payslip $record): ?string => self::reviewTooltip($record))
                    ->sortable(),
            ])
            ->groups([
                Group::make('month')->label('Month'),
                Group::make('fiscalYear.name')->label('Fiscal Year'),
                Group::make('employee_review')->label('Review Status'),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                        ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                    ->searchable()
                    ->preload(),

                SelectFilter::make('month')
                    ->label('Month')
                    ->options(collect([
                        'January', 'February', 'March', 'April', 'May', 'June',
                        'July', 'August', 'September', 'October', 'November', 'December',
                    ])->mapWithKeys(fn (string $m) => [$m => $m])->toArray()),

                SelectFilter::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name')
                    ->searchable()
                    ->preload(),

                // Employee acknowledgement of the payslip. A plain column match is
                // enough: employee_review is NOT NULL with a 'pending' default, so
                // every row holds one of the four states and there is no missing
                // case for the Pending option to also account for.
                //
                // "Answered" is its own option rather than folded into Rejected, and that is the question
                // it exists for: at the end of a run, *which objections are still waiting on somebody* —
                // which is Rejected on its own, and would be unanswerable if answering one left it there.
                SelectFilter::make('employee_review')
                    ->label('Employee Review')
                    ->options([
                        Payslip::REVIEW_PENDING => 'Pending',
                        Payslip::REVIEW_ACCEPTED => 'Accepted',
                        Payslip::REVIEW_REJECTED => 'Rejected',
                        Payslip::REVIEW_OVERRIDDEN => 'Rejected, answered',
                    ]),

                // "Who has not had theirs yet" is the question at the end of a
                // payroll run, and it is unanswerable from the review column.
                TernaryFilter::make('sent_at')
                    ->label('Sent to employee')
                    ->nullable()
                    ->placeholder('All')
                    ->trueLabel('Sent')
                    ->falseLabel('Not sent yet'),
            ])
            ->recordActions([
                // First, and for the employee it is the only one of the two they will see: Filament asks the
                // policy, and `PayslipPolicy::view()` allows their own payslip while `update()` does not.
                // This is how they reach the comment thread — see ViewPayslip.
                ViewAction::make(),
                EditAction::make(),
                self::downloadAction(),
                self::sendAction(),
                self::acceptAction(),
                self::rejectAction(),
                // Payroll's side of the same conversation: visible only on a payslip somebody has rejected,
                // and only to whoever may edit one. Defined once and also on the payslip's own View and Edit
                // pages, because that is where the thread it closes is read — see CloseObjectionAction.
                CloseObjectionAction::make(),
                // The other way to deal with an objection: correct the payslip and ask again.
                ReturnForReviewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::sendBulkAction(),
                    self::fillWorkingDaysBulkAction(),
                    DeleteBulkAction::make(),
                    self::acceptBulkAction(),
                    self::rejectBulkAction(),
                ]),
            ]);
    }

    /**
     * Release the payslip to the employee: email with the PDF attached, and the
     * same PDF on WhatsApp.
     *
     * A send, not a create hook. A payslip is recalculated on every save and
     * corrected after it is first cut, so sending automatically posted people
     * copies of figures that changed underneath them — payroll says when the month
     * is ready. Sending again is possible and is labelled as such, because the
     * honest reason to do it is that a figure was wrong.
     */
    protected static function sendAction(): Action
    {
        return Action::make('sendPayslip')
            ->label(fn (Payslip $record): string => $record->wasSent() ? 'Resend' : 'Send to employee')
            ->icon('heroicon-o-paper-airplane')
            ->color(fn (Payslip $record): string => $record->wasSent() ? 'gray' : 'primary')
            ->requiresConfirmation()
            ->modalHeading(fn (Payslip $record): string => ($record->wasSent() ? 'Resend ' : 'Send ')
                .($record->employee?->user?->name ?? 'this employee').'\'s payslip')
            ->modalDescription(fn (Payslip $record): string => self::destinationSummary($record))
            ->modalSubmitActionLabel(fn (Payslip $record): string => $record->wasSent() ? 'Send again' : 'Send')
            ->visible(fn (Payslip $record): bool => auth()->user()?->can('PayslipUpdate') ?? false)
            ->action(function (Payslip $record): void {
                try {
                    $result = app(PayslipDeliveryService::class)->send($record, resend: $record->wasSent());
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                self::reportOne($record, $result);
            });
    }

    /**
     * Fill in the attendance columns of payslips that never had them.
     *
     * For the payslips raised before the month was measured, which carry 0 for working days — "not known".
     * Each one is written the figures MonthlyPayrollService would write today, and saving recalculates the
     * money the way every save does. A payslip that already knows its month is skipped, whatever it says,
     * because what somebody entered outranks a recomputation; a locked run is skipped because the model
     * refuses to change it.
     */
    protected static function fillWorkingDaysBulkAction(): BulkAction
    {
        return BulkAction::make('fillWorkingDaysBulk')
            ->label('Fill in working days')
            ->icon('heroicon-o-calendar-days')
            ->requiresConfirmation()
            ->modalHeading('Fill in working days')
            ->modalDescription('Payslips showing 0 working days get the month\'s figures — from attendance where it is recorded, otherwise from the calendar, holidays and weekend — and their leave columns. Payslips that already have working days, and any in a signed-off month, are left unchanged.')
            ->modalSubmitActionLabel('Fill in')
            ->deselectRecordsAfterCompletion()
            ->visible(fn (): bool => auth()->user()?->can('PayslipUpdate') ?? false)
            ->action(function (Collection $records): void {
                // Fetched by the table without these, and the lazy-load guard is right to object.
                $records->load(['employee', 'fiscalYear', 'payrollRun']);

                $figures = app(AttendanceFigures::class);

                $filled = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    $known = (float) $record->total_working_days > 0;
                    $locked = $record->payrollRun?->isLocked() ?? false;

                    if ($known || $locked || ! $record->employee || ! $record->fiscalYear) {
                        $skipped++;

                        continue;
                    }

                    $record->update($figures->for($record->employee, $record->month, $record->fiscalYear));
                    $figures->settle($record->employee, $record->month, $record->fiscalYear, $record);
                    $filled++;
                }

                Notification::make()->success()
                    ->title("Filled in {$filled} payslip(s).")
                    ->body($skipped > 0 ? "{$skipped} already had working days or belong to a signed-off month, and were left unchanged." : null)
                    ->send();
            });
    }

    protected static function sendBulkAction(): BulkAction
    {
        return BulkAction::make('sendPayslipBulk')
            ->label('Send to employees')
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->modalHeading('Send payslips')
            ->modalDescription('Each employee is emailed their payslip with the PDF attached, and sent the same PDF on WhatsApp. Anyone who already has theirs is skipped — use Resend on the row to send one again.')
            ->modalSubmitActionLabel('Send')
            ->deselectRecordsAfterCompletion()
            ->visible(fn (): bool => auth()->user()?->can('PayslipUpdate') ?? false)
            ->action(function (Collection $records): void {
                $service = app(PayslipDeliveryService::class);

                $sent = 0;
                $skipped = 0;
                $problems = [];

                foreach ($records as $record) {
                    if ($record->wasSent()) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $result = $service->send($record);
                    } catch (\InvalidArgumentException $e) {
                        $problems[] = self::who($record).': '.$e->getMessage();

                        continue;
                    }

                    $result['sent'] ? $sent++ : null;

                    foreach ($result['errors'] as $error) {
                        $problems[] = self::who($record).': '.$error;
                    }
                }

                // Every failure named, not a count. "3 of 14 failed" leaves
                // somebody opening fourteen employee records to find out which.
                Notification::make()
                    ->status($problems === [] ? 'success' : 'warning')
                    ->title($sent.' '.\Illuminate\Support\Str::plural('payslip', $sent).' sent'
                        .($skipped > 0 ? ", {$skipped} already sent and skipped" : ''))
                    ->body($problems === [] ? null : implode(' · ', array_slice($problems, 0, 8)))
                    ->persistent($problems !== [])
                    ->send();
            });
    }

    /** Where this payslip is about to go, said before it goes. */
    protected static function destinationSummary(Payslip $record): string
    {
        $employee = $record->employee;
        $email = $employee?->user?->email ?: $employee?->personal_email;
        $number = PhoneNumber::e164($employee?->phone) ?? PhoneNumber::e164($employee?->secondary_phone);

        $lines = [
            $email ? "Email with the PDF attached to {$email}." : 'No email address on the employee record.',
            $number ? "WhatsApp document to +{$number}." : 'No usable phone number on the employee record.',
        ];

        if ($record->wasSent()) {
            $lines[] = 'Already sent '.$record->sent_at->diffForHumans().'.';
        }

        return implode(' ', $lines);
    }

    /** @param array{email: string|null, whatsapp: string|null, errors: array<int, string>, sent: bool} $result */
    protected static function reportOne(Payslip $record, array $result): void
    {
        $went = array_filter([
            $result['email'] ? 'email to '.$result['email'] : null,
            $result['whatsapp'] ? 'WhatsApp' : null,
        ]);

        Notification::make()
            ->status($result['sent'] ? ($result['errors'] === [] ? 'success' : 'warning') : 'danger')
            ->title($result['sent']
                ? self::who($record).' was sent their payslip ('.implode(', ', $went).')'
                : 'Nothing could be sent to '.self::who($record))
            ->body($result['errors'] === [] ? null : implode(' · ', $result['errors']))
            ->persistent($result['errors'] !== [])
            ->send();
    }

    protected static function who(Payslip $record): string
    {
        return $record->employee?->user?->name ?: ($record->employee?->employee_id ?? "Payslip #{$record->id}");
    }

    // --- DownloadPayslip (showOnTableRow, withoutConfirmation) ---
    protected static function downloadAction(): Action
    {
        return Action::make('downloadPayslip')
            ->label('Download Payslip')
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (Payslip $record): bool => auth()->user()?->can('runAction', $record) ?? false)
            ->action(function (Payslip $record) {
                try {
                    $service = app(PayslipService::class);
                    $pdf = $service->renderPdf($record);

                    /*
                     * `raw()`, not `toResponse()->getContent()`.
                     *
                     * This used to echo the latter, and on a host without Node — which is production,
                     * where `pdf.driver=auto` falls back to Dompdf — `toResponse()` returned a
                     * `StreamedResponse`, whose `getContent()` is `false` by contract. `echo false`
                     * prints nothing, so the payslip downloaded as a **0-byte PDF**: a file that looks
                     * like a document until somebody opens it. On a machine with Node the same line
                     * worked, which is why it survived.
                     *
                     * The bytes are what this needs, so it asks for the bytes. `raw()` refuses to be
                     * empty, so a broken engine is now an error rather than an empty file.
                     */
                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->raw();
                    }, $service->pdfFilename($record));

                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    /**
     * The line under the review badge: the employee's objection, and who entered the review.
     *
     * Null for the ordinary cases — pending, and accepted by the employee themselves — so the column stays
     * one line high for almost every row.
     */
    protected static function reviewNote(Payslip $record): ?string
    {
        // The objection survives the override — that is the point of the state being called `overridden`
        // rather than `accepted` — so it is shown for both.
        $reason = in_array($record->employee_review, [Payslip::REVIEW_REJECTED, Payslip::REVIEW_OVERRIDDEN], true)
            ? trim((string) $record->employee_rejection_reason)
            : '';

        $reply = $record->isReviewOverridden()
            ? trim((string) $record->latestObjectionReply()?->body)
            : '';

        $note = $record->reviewWasRecordedOnBehalf()
            ? 'on behalf, by '.($record->employee_review_recorded_by_name ?: 'an administrator')
            : '';

        return implode(' — ', array_filter([
            $reason === '' ? null : '"'.Str::limit($reason, 60).'"',
            $reply === '' ? null : 'answered: '.Str::limit($reply, 60),
            $note === '' ? null : $note,
        ])) ?: null;
    }

    /**
     * The whole conversation, on hover: what the employee said, and what payroll answered.
     *
     * The description above is truncated so the row stays one line; this is where the sentences are read in
     * full without opening the payslip.
     */
    protected static function reviewTooltip(Payslip $record): ?string
    {
        $parts = array_filter([
            filled($record->employee_rejection_reason) && ! $record->isPendingReview()
                ? 'Employee: '.$record->employee_rejection_reason
                : null,
            $record->isReviewOverridden() && filled($record->latestObjectionReply()?->body)
                ? 'Last reply: '.$record->latestObjectionReply()?->body
                : null,
        ]);

        return $parts === [] ? null : implode(' | ', $parts);
    }

    // --- AcceptPayslip ---
    protected static function acceptAction(): Action
    {
        return Action::make('acceptPayslip')
            ->label('Accept Payslip')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Payslip $record): bool => self::canReview($record))
            ->action(function (Payslip $record) {
                try {
                    $record->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);
                    Notification::make()->title('Payslip accepted — thank you.')->success()->send();
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    // --- RejectPayslip (reason textarea, required, max 255) ---
    protected static function rejectAction(): Action
    {
        return Action::make('rejectPayslip')
            ->label('Reject Payslip')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Payslip $record): bool => self::canReview($record))
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Tell the accounts team what looks wrong'),
            ])
            ->action(function (array $data, Payslip $record) {
                try {
                    $record->recordEmployeeReview(Payslip::REVIEW_REJECTED, $data['reason']);
                    Notification::make()->title('Objection recorded; the accounts team will review it.')->success()->send();
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    protected static function acceptBulkAction(): BulkAction
    {
        return BulkAction::make('acceptPayslipBulk')
            ->label('Accept Payslip')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->action(function (Collection $records) {
                $done = 0;
                foreach ($records as $record) {
                    if (! self::canReview($record)) {
                        continue;
                    }
                    try {
                        $record->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);
                        $done++;
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }
                Notification::make()->title("Payslip accepted: {$done} processed.")->success()->send();
            });
    }

    protected static function rejectBulkAction(): BulkAction
    {
        return BulkAction::make('rejectPayslipBulk')
            ->label('Reject Payslip')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Tell the accounts team what looks wrong'),
            ])
            ->action(function (array $data, Collection $records) {
                $done = 0;
                foreach ($records as $record) {
                    if (! self::canReview($record)) {
                        continue;
                    }
                    try {
                        $record->recordEmployeeReview(Payslip::REVIEW_REJECTED, $data['reason']);
                        $done++;
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }
                Notification::make()->title("Objection recorded: {$done} processed.")->success()->send();
            });
    }

    /**
     * Mirrors AcceptPayslip/RejectPayslip authorizedToRun(): only the owning
     * employee, and only while the payslip is still pending review.
     */
    protected static function canReview(Payslip $record): bool
    {
        return $record->isPendingReview()
            && $record->employee_id
            && app(EmployeeAccess::class)
                ->accessibleEmployeeIds(auth()->user())
                ->contains($record->employee_id);
    }
}
