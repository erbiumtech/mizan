<?php

namespace App\Modules\Accounting\Filament\Resources\Banks;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\Banks\Pages\CreateBank;
use App\Modules\Accounting\Filament\Resources\Banks\Pages\EditBank;
use App\Modules\Accounting\Filament\Resources\Banks\Pages\ListBanks;
use App\Modules\Accounting\Filament\Resources\Banks\Schemas\BankForm;
use App\Modules\Accounting\Filament\Resources\Banks\Tables\BanksTable;
use App\Modules\Core\Models\Bank;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BankResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Bank::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'bank_name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['bank_code', 'bank_name', 'bank_short_code'];
    }

    public static function form(Schema $schema): Schema
    {
        return BankForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BanksTable::configure($table);
    }

    /**
     * None.
     *
     * There was a read-only Employees list here, added "for parity with the Nova HasMany field" — and
     * Nova has since been removed entirely (docs/filament-laravel13-migration-plan.md), so the reason it
     * existed no longer does. It needed `Bank::employees()`, which is the relation that made the bank
     * list depend on Employees; see App\Modules\Core\Models\Bank. The Employees list carries
     * `bank_code` and `bank_short_code` as sortable columns, so "who banks here" is still answerable
     * from the screen that owns employees.
     */
    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBanks::route('/'),
            'create' => CreateBank::route('/create'),
            'edit' => EditBank::route('/{record}/edit'),
        ];
    }
}
