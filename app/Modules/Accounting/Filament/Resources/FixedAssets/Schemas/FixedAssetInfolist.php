<?php

namespace App\Modules\Accounting\Filament\Resources\FixedAssets\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Accounting\Models\FixedAsset;
use App\Support\CompanyCurrency;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** A fixed asset read in the list's view sheet — docs/side-sheets.md. */
class FixedAssetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $customFields = CustomFieldsSchema::infolistEntries(FixedAsset::class);

        return $schema->components([
            Section::make(__('Asset'))
                ->columns(3)
                ->schema([
                    TextEntry::make('asset_code')->label(__('Asset code'))->copyable()->placeholder('—'),
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            'active' => 'success',
                            'fully_depreciated' => 'warning',
                            'disposed' => 'danger',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (string $state): string => __(ucfirst(str_replace('_', ' ', $state))))
                        ->placeholder('—'),
                    TextEntry::make('account.name')->label(__('Asset Account'))->placeholder('—'),
                    TextEntry::make('purchase_date')->label(__('Purchase Date'))->date(),
                ]),

            Section::make(__('Depreciation'))
                ->columns(3)
                ->schema([
                    TextEntry::make('purchase_cost')->label(__('Purchase Cost'))->money(CompanyCurrency::code()),
                    TextEntry::make('salvage_value')->label(__('Salvage Value'))->money(CompanyCurrency::code())->placeholder('—'),
                    TextEntry::make('accumulated_depreciation')->label(__('Accumulated Depreciation'))->money(CompanyCurrency::code()),
                    TextEntry::make('book_value')
                        ->label(__('Book Value'))
                        ->state(fn (FixedAsset $record): float => $record->book_value)
                        ->money(CompanyCurrency::code()),
                    TextEntry::make('depreciation_method')
                        ->label(__('Depreciation Method'))
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'straight_line' => __('Straight Line'),
                            'declining_balance' => __('Declining Balance'),
                            default => $state,
                        }),
                    TextEntry::make('useful_life_months')->label(__('Useful Life (months)')),
                ]),

            Section::make(__('Custom fields'))
                ->columns(2)
                ->schema($customFields)
                ->visible($customFields !== []),
        ]);
    }
}
