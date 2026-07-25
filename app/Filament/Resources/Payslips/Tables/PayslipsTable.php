<?php

namespace App\Filament\Resources\Payslips\Tables;

use App\Models\Payslip;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

class PayslipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.employee_id')
                    ->label('Employee')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('payslips.id', 'like', "%{$search}%")
                        ->orWhereHas('employee', fn ($q) => $q
                            ->where('employee_id', 'like', "%{$search}%")
                            ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))))
                    ->sortable(),

                TextColumn::make('month')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fiscalYear.name')
                    ->label('Fiscal Year')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('net_salary')
                    ->label('Net Salary')
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
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'employee_id')
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
            && $record->employee?->user_id === auth()->id();
    }

    /**
     * Generate (if missing) and return the download URL for the payslip PDF —
     * parity with Nova DownloadPayslip::handle().
     */
    protected static function generatePayslipPdf(Payslip $payslip): string
    {
        $month = $payslip->month;
        $yearName = $payslip->fiscalYear ? $payslip->fiscalYear->name : 'Unknown-Year';
        $cleanFileNamePart = $month . '-' . str_replace([' ', '/', '\\'], '-', $yearName);
        $customEmpId = $payslip->employee->employee_id;

        $fileName = 'payslips/' . $customEmpId . '-' . $cleanFileNamePart . '.pdf';

        if (! Storage::disk('public')->exists($fileName)) {
            if (! Storage::disk('public')->exists('payslips')) {
                Storage::disk('public')->makeDirectory('payslips');
            }

            $absolutePath = Storage::disk('public')->path($fileName);

            Pdf::view('pdfs.payslip', ['data' => $payslip])
                ->format('a4')
                ->withBrowsershot(fn (\Spatie\Browsershot\Browsershot $b) => $b->setNodeBinary(config('services.node.binary'))->setNpmBinary(config('services.node.npm')))
                ->save($absolutePath);

            $payslip->update(['pdf_path' => $fileName]);
        }

        return Storage::disk('public')->url($fileName);
    }
}
