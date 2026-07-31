<?php

namespace App\Filament\Resources\TableViews;

use App\Filament\Resources\TableViews\Pages\EditTableView;
use App\Filament\Resources\TableViews\Pages\ListTableViews;
use App\Filament\Resources\TableViews\Schemas\TableViewForm;
use App\Filament\Resources\TableViews\Tables\TableViewsTable;
use App\Models\TableView;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TableViewResource extends Resource
{
    protected static ?string $model = TableView::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'name';

    /** Managing all companies' saved views is an Administrator concern. */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Administrator') || auth()->user()?->isSuperAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return TableViewForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TableViewsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTableViews::route('/'),
            'edit' => EditTableView::route('/{record}/edit'),
        ];
    }
}
