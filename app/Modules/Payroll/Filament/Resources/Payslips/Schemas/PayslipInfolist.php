<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Schemas;

use App\Modules\Payroll\Models\Payslip;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * A payslip as the person it belongs to reads it.
 *
 * The edit form is the payroll team's screen and it needs `PayslipUpdate` — which an employee does not hold,
 * and should not. That left them with a list row, a PDF and an Accept/Reject button, and **no way to reach
 * the conversation about their own payslip**: relation managers live on a record page, and the only record
 * page was the one they cannot open.
 *
 * So this is the read-only half: the figures, the objection if there is one, and — through `ViewPayslip` —
 * the Comments tab underneath, where the employee can reply. Nothing here is editable by anybody; correcting
 * a payslip is still the form.
 *
 * **Deliberately short.** A payslip has thirty columns and the PDF is the document; what belongs on a screen
 * somebody opens to answer a question is the month, what they were paid, and what is being discussed.
 */
class PayslipInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * The objection first, above the figures.
             *
             * Whoever opens this page while a rejection is open — the employee who raised it, or the person
             * about to answer it — is here because of it. `PayslipForm::objection()` renders the same summary
             * on the edit screen, so the two cannot say different things.
             */
            Section::make('Employee objection')
                ->visible(fn (Payslip $record): bool => in_array(
                    $record->employee_review,
                    [Payslip::REVIEW_REJECTED, Payslip::REVIEW_OVERRIDDEN],
                    true,
                ))
                ->schema([
                    TextEntry::make('employee_rejection_reason')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        // Rendered rather than shown as tags. Safe because `objection()` escapes the two
                        // sentences people wrote before turning the summary around them into markdown — see
                        // `PayslipForm::objection()`, which is also what the edit screen draws.
                        ->html()
                        ->state(fn (Payslip $record): HtmlString => PayslipForm::objection($record)),
                ]),

            Section::make('Payslip')
                ->columns(3)
                ->schema([
                    TextEntry::make('employee.employee_id')
                        ->label('Employee')
                        ->formatStateUsing(fn ($state, Payslip $record): string => $record->employee?->display_label ?? (string) $state),
                    TextEntry::make('month'),
                    TextEntry::make('fiscalYear.name')->label('Fiscal year'),

                    TextEntry::make('paid_days')->label('Paid days'),
                    TextEntry::make('lop_days')->label('Unpaid days'),
                    TextEntry::make('leaves_taken')->label('Leave taken'),
                ]),

            Section::make('Pay')
                ->columns(3)
                ->schema([
                    TextEntry::make('total_earnings')->money('PKR'),
                    TextEntry::make('total_deductions')->money('PKR'),
                    TextEntry::make('net_salary')->money('PKR')->weight('bold'),
                ]),

            Section::make('Acknowledgement')
                ->columns(3)
                ->schema([
                    TextEntry::make('employee_review')
                        ->label('Review')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            Payslip::REVIEW_OVERRIDDEN => 'Rejected, answered',
                            null => 'Pending',
                            default => ucfirst($state),
                        })
                        ->color(fn (?string $state): string => match ($state) {
                            Payslip::REVIEW_ACCEPTED => 'success',
                            Payslip::REVIEW_REJECTED => 'danger',
                            Payslip::REVIEW_OVERRIDDEN => 'info',
                            default => 'warning',
                        }),
                    TextEntry::make('employee_reviewed_at')->label('Reviewed')->dateTime()->placeholder('—'),
                    TextEntry::make('sent_at')->label('Sent')->dateTime()->placeholder('not sent yet'),
                ]),
        ]);
    }
}
