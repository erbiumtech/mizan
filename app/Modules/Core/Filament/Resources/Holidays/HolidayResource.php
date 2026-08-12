<?php

namespace App\Modules\Core\Filament\Resources\Holidays;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Core\Filament\Resources\Holidays\Pages\CreateHoliday;
use App\Modules\Core\Filament\Resources\Holidays\Pages\EditHoliday;
use App\Modules\Core\Filament\Resources\Holidays\Pages\ListHolidays;
use App\Modules\Core\Filament\Resources\Holidays\Schemas\HolidayForm;
use App\Modules\Core\Filament\Resources\Holidays\Tables\HolidaysTable;
use App\Modules\Core\Models\Holiday;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The days this company does not work.
 *
 * Kept here rather than in leave or attendance for the reason FiscalYear is:
 * both read it and neither owns it, and two lists of public holidays that
 * disagree about Eid is a support call. See docs/hrms-plan.md §3.
 */
class HolidayResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Holiday::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function form(Schema $schema): Schema
    {
        return HolidayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HolidaysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHolidays::route('/'),
            'create' => CreateHoliday::route('/create'),
            'edit' => EditHoliday::route('/{record}/edit'),
        ];
    }
}
