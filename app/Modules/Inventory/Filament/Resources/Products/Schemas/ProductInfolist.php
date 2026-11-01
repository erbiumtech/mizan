<?php

namespace App\Modules\Inventory\Filament\Resources\Products\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Inventory\Models\Product;
use App\Support\CompanyCurrency;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** A product read in the list's view sheet — docs/side-sheets.md. */
class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $customFields = CustomFieldsSchema::infolistEntries(Product::class);

        return $schema->components([
            Section::make(__('Product'))
                ->columns(3)
                ->schema([
                    TextEntry::make('sku')->label(__('SKU'))->copyable(),
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('unit')->label(__('Unit')),
                    TextEntry::make('list_price')->label(__('List price'))->money(CompanyCurrency::code())->placeholder('—'),
                    TextEntry::make('valuation_method')
                        ->label(__('Valuation Method'))
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'fifo' => 'FIFO',
                            'lifo' => 'LIFO',
                            'average' => __('Average Cost'),
                            default => $state,
                        }),
                    TextEntry::make('reorder_level')->label(__('Reorder level'))->numeric(),
                    IconEntry::make('is_active')->label(__('Active'))->boolean(),
                    TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull(),
                ]),

            Section::make(__('Accounts'))
                ->columns(3)
                ->schema([
                    TextEntry::make('inventoryAccount.name')->label(__('Inventory Account'))->placeholder('—'),
                    TextEntry::make('cogsAccount.name')->label(__('COGS Account'))->placeholder('—'),
                    TextEntry::make('revenueAccount.name')->label(__('Revenue Account'))->placeholder('—'),
                ]),

            Section::make(__('Custom fields'))
                ->columns(2)
                ->schema($customFields)
                ->visible($customFields !== []),
        ]);
    }
}
