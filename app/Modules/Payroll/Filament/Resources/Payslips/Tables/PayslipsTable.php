<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Tables;

use App\Modules\Payroll\Models\Payslip;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use App\Support\Pdf\Pdf;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PayslipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.tables.saved-views-bar'))
            ->modifyQueryUsing(fn ($query) => $query->with('employee.user'))
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

                TextColumn::make('employee_review')
                    ->label('Employee Review')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'warning',
                        'accepted' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    // An acknowledgement entered by somebody signed in as the
                    // employee reads exactly like the employee's own unless the
                    // list says otherwise.
                    ->description(fn (Payslip $record): ?string => $record->reviewWasRecordedOnBehalf()
                        ? 'on behalf, by '.($record->employee_review_recorded_by_name ?: 'an administrator')
                        : null)
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
                // every row holds one of the three states and there is no missing
                // case for the Pending option to also account for.
                SelectFilter::make('employee_review')
                    ->label('Employee Review')
                    ->options([
                        Payslip::REVIEW_PENDING => 'Pending',
                        Payslip::REVIEW_ACCEPTED => 'Accepted',
                        Payslip::REVIEW_REJECTED => 'Rejected',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                self::downloadAction(),
                self::acceptAction(),
                self::rejectAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    self::acceptBulkAction(),
                    self::rejectBulkAction(),
                ]),
            ]);
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
                    $url = self::generatePayslipPdf($record);

                    return response()->redirectTo($url);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
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

    /**
     * Generate (if missing) and return the download URL for the payslip PDF —
     * parity with Nova DownloadPayslip::handle().
     */
    protected static function generatePayslipPdf(Payslip $payslip): string
    {
        $month = $payslip->month;
        $yearName = $payslip->fiscalYear ? $payslip->fiscalYear->name : 'Unknown-Year';
        $cleanFileNamePart = $month.'-'.str_replace([' ', '/', '\\'], '-', $yearName);
        $customEmpId = $payslip->employee->employee_id;

        $fileName = 'payslips/'.$customEmpId.'-'.$cleanFileNamePart.'.pdf';

        if (! Storage::disk('public')->exists($fileName)) {
            if (! Storage::disk('public')->exists('payslips')) {
                Storage::disk('public')->makeDirectory('payslips');
            }

            $absolutePath = Storage::disk('public')->path($fileName);

            Pdf::view('pdfs.payslip', ['data' => $payslip])
                ->format('a4')
                ->save($absolutePath);

            $payslip->update(['pdf_path' => $fileName]);
        }

        return Storage::disk('public')->url($fileName);
    }
}
