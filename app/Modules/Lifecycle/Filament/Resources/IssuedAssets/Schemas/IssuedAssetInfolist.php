<?php

namespace App\Modules\Lifecycle\Filament\Resources\IssuedAssets\Schemas;

use App\Modules\Lifecycle\Models\IssuedAsset;
use App\Support\CompanyCurrency;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * An issued asset read in the list's view sheet — docs/side-sheets.md. Warranty and return read with
 * the list's own colours; the fixed-asset link shows only when accounting is on, as on the form.
 */
class IssuedAssetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('description')->label(__('Description'))->columnSpanFull(),
            TextEntry::make('employee.display_label')->label(__('Issued to'))->placeholder('—'),
            TextEntry::make('asset_kind')
                ->label(__('Kind'))
                ->badge()
                ->color('gray')
                ->formatStateUsing(fn (string $state): string => IssuedAsset::kindOptions($state)[$state] ?? IssuedAsset::kindLabel($state)),
            TextEntry::make('serial_no')->label(__('Serial number'))->copyable()->placeholder('—'),
            TextEntry::make('tag')->label(__('Asset tag'))->copyable()->placeholder('—'),
            TextEntry::make('vendor')->label(__('Bought from'))->placeholder('—'),
            TextEntry::make('warranty_until')
                ->label(__('Warranty until'))
                ->date()
                ->placeholder('—')
                ->color(fn (IssuedAsset $record): string => $record->isUnderWarranty() === false ? 'danger' : 'gray')
                ->helperText(fn (IssuedAsset $record): ?string => $record->isUnderWarranty() === false ? __('Expired') : null),
            TextEntry::make('issued_on')->label(__('Issued on'))->date(),
            TextEntry::make('returned_on')
                ->label(__('Returned'))
                ->date()
                ->placeholder(__('Still out'))
                ->color(fn (IssuedAsset $record): string => $record->isReturned() ? 'success' : 'warning'),
            TextEntry::make('value')->label(__('Value'))->money(CompanyCurrency::code())->placeholder('—'),
            TextEntry::make('fixedAsset.name')
                ->label(__('On the books as'))
                ->placeholder('—')
                ->visible(fn (): bool => modules()->enabled('accounting')),
            TextEntry::make('condition_note')->label(__('Condition'))->placeholder('—')->columnSpanFull(),
        ]);
    }
}
