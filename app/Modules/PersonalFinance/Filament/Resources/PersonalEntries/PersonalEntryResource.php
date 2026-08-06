<?php

namespace App\Modules\PersonalFinance\Filament\Resources\PersonalEntries;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\PersonalFinance\Filament\Resources\PersonalEntries\Pages\ListPersonalEntries;
use App\Modules\PersonalFinance\Filament\Resources\PersonalEntries\Tables\PersonalEntriesTable;
use App\Modules\PersonalFinance\Models\PersonalEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Everything a person has recorded, newest first.
 *
 * There is no create or edit page. Entries are made through the Record income /
 * Record expense / Transfer actions on the list, each of which builds a balanced
 * pair of lines itself — a generic form would ask somebody to hand-write debits
 * and credits to log a bus fare, and they would stop using it. Corrections are
 * made by deleting and re-recording, which for a personal ledger with no
 * approval trail is honest and simpler than an edit that has to rewrite both
 * sides.
 */
class PersonalEntryResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = PersonalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?string $modelLabel = 'Transaction';

    protected static ?string $pluralModelLabel = 'My Transactions';

    public static function table(Table $table): Table
    {
        return PersonalEntriesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        // The three actions on the list page are how an entry is made; a bare
        // "New" button leading to a debits-and-credits form is not the way in.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPersonalEntries::route('/'),
        ];
    }
}
