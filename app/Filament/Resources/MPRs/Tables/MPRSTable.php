<?php

namespace App\Filament\Resources\MPRs\Tables;

use App\Models\MPR;
use App\Models\User;
use App\Services\MprPdfService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class MPRSTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('User')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('mpr_date')
                    ->label('Date')
                    ->date('d-m-Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('User Name')
                    ->options(fn (): array => User::pluck('name', 'id')->toArray())
                    ->searchable(),
            ])
            ->headerActions([
                self::downloadComparisonAction(),
            ])
            ->recordActions([
                EditAction::make(),
                self::downloadSingleAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Parity with Nova DownloadSingleMprPdf ("Download PDF"): reuse pdf_path or generate a single report,
     * then open the resulting file in a new tab.
     */
    protected static function downloadSingleAction(): Action
    {
        return Action::make('downloadSinglePdf')
            ->label('Download PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->requiresConfirmation(false)
            ->action(function (MPR $record) {
                $userName = $record->user->name ?? 'User';
                $cleanName = str_replace([' ', '/', '\\'], '_', $userName);
                $fileName = 'Mpr/' . $cleanName . '_' . time() . '.pdf';

                if ($record->pdf_path && Storage::disk('public')->exists($record->pdf_path)) {
                    $fileName = $record->pdf_path;
                } else {
                    $pdfService = new MprPdfService;
                    $result = $pdfService->generateSingleReport($record->toArray());

                    $directory = storage_path('app/public/Mpr');
                    if (! file_exists($directory)) {
                        mkdir($directory, 0755, true);
                    }

                    $result['pdf']->save(storage_path('app/public/' . $fileName));
                    $record->update(['pdf_path' => $fileName]);
                }

                return redirect()->away(url('storage/' . $fileName));
            });
    }

    /**
     * Parity with Nova DownloadMprPdf ("Generate / Download PDF", standalone): generate a comparison
     * report (recent two MPRs) for the chosen user and open it in a new tab. Non-admins only see themselves.
     */
    protected static function downloadComparisonAction(): Action
    {
        return Action::make('downloadComparisonPdf')
            ->label('Generate / Download PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->schema(function (): array {
                $user = auth()->user();

                if ($user && ! $user->hasRole('Administrator')) {
                    return [
                        Select::make('user_id')
                            ->label('User')
                            ->options([$user->id => $user->name])
                            ->default($user->id)
                            ->helperText('Your profile is automatically selected for this action.'),
                    ];
                }

                return [
                    Select::make('user_id')
                        ->label('Select User')
                        ->options(User::where('status', 1)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->helperText('Generate Recent Two Pdfs of Selected User'),
                ];
            })
            ->action(function (array $data) {
                $userId = $data['user_id'] ?? auth()->id();

                if (! $userId) {
                    Notification::make()->title('Select any User from dropdown!')->danger()->send();

                    return;
                }

                $user = User::find($userId);
                $pdfService = new MprPdfService;
                $result = $pdfService->generateComparisonReport($userId);

                if (! $result || $result['empty']) {
                    Notification::make()->title('This user has no MPR Record in database')->danger()->send();

                    return;
                }

                $userName = $user->name ?? 'User';
                $cleanName = str_replace([' ', '/', '\\'], '_', $userName);
                $customFileName = $cleanName . '_Comparison_' . time() . '.pdf';

                $directory = storage_path('app/public/Mpr');
                if (! file_exists($directory)) {
                    mkdir($directory, 0755, true);
                }

                $result['pdf']->save($directory . '/' . $customFileName);

                return redirect()->away(url('storage/Mpr/' . $customFileName));
            });
    }
}
