<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRecords;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Pages\CreateLabourRecord;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Pages\EditLabourRecord;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Pages\ListLabourRecords;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Schemas\LabourRecordForm;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Tables\LabourRecordsTable;
use App\Modules\ConstructionCosting\Models\LabourRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Site sheets — `docs/construction-management-plan.md` §7.1.
 *
 * **The second register site staff create in**, after requisitions and receipts, and for the same reason: the ganger is
 * the only person who knows who turned up and what they did. Approving is somebody else's act, because approving is
 * what books the money and freezes the rate the day was costed at.
 *
 * Time is entered in **minutes** and shown in hours. §7.1 is explicit about why the column is minutes — "a rate
 * multiplied by a rounded decimal of hours accumulates visible error across a month" — and equally clear that a site
 * sheet is discussed in hours, which is why the register prints both.
 */
class LabourRecordResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = LabourRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?int $navigationSort = 53;

    protected static ?string $label = 'Site sheet';

    protected static ?string $pluralLabel = 'Site sheets';

    public static function getEloquentQuery(): Builder
    {
        // Every row names the worker, the job and the code, and the reversal action reads both entries (§18.3).
        return parent::getEloquentQuery()->with(['worker', 'job', 'costCode', 'trade']);
    }

    public static function form(Schema $schema): Schema
    {
        return LabourRecordForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LabourRecordsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLabourRecords::route('/'),
            'create' => CreateLabourRecord::route('/create'),
            'edit' => EditLabourRecord::route('/{record}/edit'),
        ];
    }
}
