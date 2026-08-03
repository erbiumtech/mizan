<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntryLines;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\JournalEntryLines\Pages\CreateJournalEntryLine;
use App\Modules\Accounting\Filament\Resources\JournalEntryLines\Pages\EditJournalEntryLine;
use App\Modules\Accounting\Filament\Resources\JournalEntryLines\Pages\ListJournalEntryLines;
use App\Modules\Accounting\Filament\Resources\JournalEntryLines\Schemas\JournalEntryLineForm;
use App\Modules\Accounting\Filament\Resources\JournalEntryLines\Tables\JournalEntryLinesTable;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class JournalEntryLineResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = JournalEntryLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'id';

    // Nova: $displayInNavigation = false
    protected static bool $shouldRegisterNavigation = false;

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'description'];
    }

    // Nova: authorizedToCreate() -> can 'create' JournalEntry
    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', JournalEntry::class) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return JournalEntryLineForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JournalEntryLinesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalEntryLines::route('/'),
            'create' => CreateJournalEntryLine::route('/create'),
            'edit' => EditJournalEntryLine::route('/{record}/edit'),
        ];
    }
}
