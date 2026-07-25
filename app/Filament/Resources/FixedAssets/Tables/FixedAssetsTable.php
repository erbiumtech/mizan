<?php

namespace App\Filament\Resources\FixedAssets\Tables;

use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class FixedAssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('asset_code')
                    ->label('Asset Code')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('account.name')
                    ->label('Asset Account')
                    ->sortable(),

                TextColumn::make('purchase_date')
                    ->label('Purchase Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('purchase_cost')
                    ->label('Purchase Cost')
                    ->money('PKR'),

                TextColumn::make('accumulated_depreciation')
                    ->label('Accumulated Depreciation')
                    ->money('PKR'),

                TextColumn::make('book_value')
                    ->label('Book Value')
                    ->money('PKR')
                    ->state(fn (FixedAsset $record): float => $record->book_value)
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'fully_depreciated' => 'warning',
                        'disposed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                ...\App\Filament\Support\CustomFieldsSchema::tableColumns(\App\Models\FixedAsset::class),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'fully_depreciated' => 'Fully Depreciated',
                        'disposed' => 'Disposed',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                ...self::assetActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ...self::assetBulkActions(),
                ]),
            ]);
    }

    /**
     * Per-record actions — parity with Nova RunDepreciation / DisposeFixedAsset.
     *
     * @return array<Action>
     */
    protected static function assetActions(): array
    {
        return [
            Action::make('runDepreciation')
                ->label('Run Depreciation')
                ->icon('heroicon-o-calculator')
                ->requiresConfirmation()
                ->modalDescription('Book one month of depreciation for the selected asset? Entries are posted immediately.')
                ->visible(fn (FixedAsset $record): bool => auth()->user()?->can('depreciate', $record) ?? false)
                ->schema(self::depreciationFields())
                ->action(fn (array $data, FixedAsset $record) => self::runDepreciation(collect([$record]), $data)),

            Action::make('disposeAsset')
                ->label('Dispose Asset')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
                ->modalDescription('This writes the asset off the books (remaining book value becomes a loss) and cannot be undone. Continue?')
                ->visible(fn (FixedAsset $record): bool => auth()->user()?->can('dispose', $record) ?? false)
                ->action(fn (FixedAsset $record) => self::dispose(collect([$record]))),
        ];
    }

    /**
     * Bulk equivalents.
     *
     * @return array<BulkAction>
     */
    protected static function assetBulkActions(): array
    {
        return [
            BulkAction::make('runDepreciationBulk')
                ->label('Run Depreciation')
                ->icon('heroicon-o-calculator')
                ->requiresConfirmation()
                ->modalDescription('Book one month of depreciation for the selected assets? Entries are posted immediately.')
                ->visible(fn (): bool => auth()->user()?->can('FixedAssetDepreciate') ?? false)
                ->schema(self::depreciationFields())
                ->action(fn (array $data, Collection $records) => self::runDepreciation($records, $data)),

            BulkAction::make('disposeAssetBulk')
                ->label('Dispose Asset')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
                ->modalDescription('This writes the asset off the books (remaining book value becomes a loss) and cannot be undone. Continue?')
                ->visible(fn (): bool => auth()->user()?->can('FixedAssetDispose') ?? false)
                ->action(fn (Collection $records) => self::dispose($records)),
        ];
    }

    protected static function depreciationFields(): array
    {
        return [
            DatePicker::make('month')
                ->label('Month')
                ->helperText('Any date in the month to depreciate; defaults to last month.')
                ->default(now()->subMonth()->toDateString()),
        ];
    }

    /**
     * Book one month of depreciation across the given assets.
     */
    protected static function runDepreciation(Collection $records, array $data): void
    {
        $service = app(DepreciationService::class);
        $month = Carbon::parse($data['month'] ?? now()->subMonth());
        $fiscalYearId = FiscalYear::where('is_active', true)->first()?->id;
        $booked = 0;

        try {
            foreach ($records as $asset) {
                if ($service->depreciateAsset($asset, $month, $fiscalYearId)) {
                    $booked++;
                }
            }
        } catch (\InvalidArgumentException | \Exception $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title($booked > 0
                ? "Depreciation booked for {$booked} asset(s)."
                : 'Nothing to book (already depreciated or not eligible).')
            ->success()
            ->send();
    }

    protected static function dispose(Collection $records): void
    {
        $service = app(DepreciationService::class);

        try {
            foreach ($records as $asset) {
                $service->dispose($asset);
            }
        } catch (\InvalidArgumentException | \Exception $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Asset(s) disposed and written off.')->success()->send();
    }
}
