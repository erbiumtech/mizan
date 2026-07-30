<?php

namespace App\Modules\Inventory\Filament\Resources\Products\Tables;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.tables.saved-views-bar'))
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('valuation_method')
                    ->label('Valuation')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->color(fn (string $state): string => match ($state) {
                        'fifo' => 'info',
                        'lifo' => 'warning',
                        'average' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('on_hand')
                    ->label('On Hand')
                    ->state(fn (Product $record): float => round((float) ($record->on_hand_qty ?? 0), 2)),

                TextColumn::make('stock_value')
                    ->label('Stock Value')
                    ->money('PKR')
                    ->state(fn (Product $record): float => round((float) ($record->stock_in_value ?? 0) - (float) ($record->stock_out_value ?? 0), 2)),

                TextColumn::make('stock_status')
                    ->label('Stock Status')
                    ->badge()
                    ->state(fn (Product $record): string => round((float) ($record->on_hand_qty ?? 0), 2) <= (float) $record->reorder_level ? 'low' : 'ok')
                    ->color(fn (string $state): string => $state === 'low' ? 'danger' : 'success'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                ...CustomFieldsSchema::tableColumns(Product::class),
            ])
            ->groups([
                Group::make('valuation_method')->label('Valuation Method'),
                Group::make('is_active')->label('Active'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                ...self::stockActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ...self::stockBulkActions(),
                ]),
            ]);
    }

    /**
     * Per-record stock actions — parity with Nova ReceiveStock / RecordSale / AdjustStock.
     *
     * @return array<Action>
     */
    protected static function stockActions(): array
    {
        return [
            Action::make('receiveStock')
                ->label('Receive Stock')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => auth()->user()?->can('StockMove') ?? false)
                ->schema(self::receiveFields())
                ->action(fn (array $data, Product $record) => self::run(fn (InventoryService $s) => $s->purchase($record, (float) $data['quantity'], (float) $data['unit_cost'], $data['date'], $data['reference'] ?? null), 'Receive Stock')),

            Action::make('recordSale')
                ->label('Record Sale')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->can('StockMove') ?? false)
                ->schema(self::saleFields())
                ->action(fn (array $data, Product $record) => self::run(fn (InventoryService $s) => $s->sale($record, (float) $data['quantity'], (float) $data['unit_price'], $data['date'], $data['reference'] ?? null), 'Record Sale')),

            Action::make('adjustStock')
                ->label('Adjust Stock')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn (): bool => auth()->user()?->can('StockAdjust') ?? false)
                ->schema(self::adjustFields())
                ->action(fn (array $data, Product $record) => self::run(fn (InventoryService $s) => $s->adjust($record, (float) $data['quantity'], $data['date'], isset($data['unit_cost']) && $data['unit_cost'] !== null ? (float) $data['unit_cost'] : null, $data['reference'] ?? null), 'Adjust Stock')),
        ];
    }

    /**
     * Bulk equivalents — run over each selected product.
     *
     * @return array<BulkAction>
     */
    protected static function stockBulkActions(): array
    {
        return [
            BulkAction::make('receiveStockBulk')
                ->label('Receive Stock')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => auth()->user()?->can('StockMove') ?? false)
                ->schema(self::receiveFields())
                ->action(fn (array $data, Collection $records) => self::runBulk($records, fn (InventoryService $s, Product $p) => $s->purchase($p, (float) $data['quantity'], (float) $data['unit_cost'], $data['date'], $data['reference'] ?? null), 'Receive Stock')),

            BulkAction::make('recordSaleBulk')
                ->label('Record Sale')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->can('StockMove') ?? false)
                ->schema(self::saleFields())
                ->action(fn (array $data, Collection $records) => self::runBulk($records, fn (InventoryService $s, Product $p) => $s->sale($p, (float) $data['quantity'], (float) $data['unit_price'], $data['date'], $data['reference'] ?? null), 'Record Sale')),

            BulkAction::make('adjustStockBulk')
                ->label('Adjust Stock')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn (): bool => auth()->user()?->can('StockAdjust') ?? false)
                ->schema(self::adjustFields())
                ->action(fn (array $data, Collection $records) => self::runBulk($records, fn (InventoryService $s, Product $p) => $s->adjust($p, (float) $data['quantity'], $data['date'], isset($data['unit_cost']) && $data['unit_cost'] !== null ? (float) $data['unit_cost'] : null, $data['reference'] ?? null), 'Adjust Stock')),
        ];
    }

    protected static function receiveFields(): array
    {
        return [
            DatePicker::make('date')->required()->default(now()->toDateString()),
            TextInput::make('quantity')->numeric()->step(0.01)->required()->minValue(0.01),
            TextInput::make('unit_cost')->label('Unit Cost')->numeric()->step(0.01)->required()->minValue(0),
            TextInput::make('reference')->nullable(),
        ];
    }

    protected static function saleFields(): array
    {
        return [
            DatePicker::make('date')->required()->default(now()->toDateString()),
            TextInput::make('quantity')->numeric()->step(0.01)->required()->minValue(0.01),
            TextInput::make('unit_price')->label('Unit Price')->numeric()->step(0.01)->required()->minValue(0),
            TextInput::make('reference')->nullable(),
        ];
    }

    protected static function adjustFields(): array
    {
        return [
            DatePicker::make('date')->required()->default(now()->toDateString()),
            TextInput::make('quantity')->numeric()->step(0.01)->required(),
            TextInput::make('unit_cost')->label('Unit Cost')->numeric()->step(0.01)->nullable()->minValue(0)
                ->helperText('Required for positive adjustments'),
            TextInput::make('reference')->nullable(),
        ];
    }

    /**
     * Run a single-product stock operation, surfacing service validation errors as notifications.
     */
    protected static function run(callable $op, string $label): void
    {
        try {
            $op(app(InventoryService::class));
            Notification::make()->title("{$label}: processed.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected static function runBulk(Collection $records, callable $op, string $label): void
    {
        $service = app(InventoryService::class);
        $done = 0;
        try {
            foreach ($records as $record) {
                $op($service, $record);
                $done++;
            }
            Notification::make()->title("{$label}: {$done} product(s) processed.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
