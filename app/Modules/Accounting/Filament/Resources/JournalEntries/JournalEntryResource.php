<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntries;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Modules\Accounting\Filament\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Modules\Accounting\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Modules\Accounting\Filament\Resources\JournalEntries\RelationManagers\LinesRelationManager;
use App\Modules\Accounting\Filament\Resources\JournalEntries\Schemas\JournalEntryForm;
use App\Modules\Accounting\Filament\Resources\JournalEntries\Tables\JournalEntriesTable;
use App\Modules\Accounting\Models\JournalEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class JournalEntryResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = JournalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'entry_number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['entry_number', 'reference', 'memo'];
    }

    public static function form(Schema $schema): Schema
    {
        return JournalEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JournalEntriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalEntries::route('/'),
            'create' => CreateJournalEntry::route('/create'),
            'edit' => EditJournalEntry::route('/{record}/edit'),
        ];
    }
}
