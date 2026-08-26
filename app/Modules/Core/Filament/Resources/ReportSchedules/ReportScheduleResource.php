<?php

namespace App\Modules\Core\Filament\Resources\ReportSchedules;

use App\Modules\Core\Filament\Resources\ReportSchedules\Pages\CreateReportSchedule;
use App\Modules\Core\Filament\Resources\ReportSchedules\Pages\EditReportSchedule;
use App\Modules\Core\Filament\Resources\ReportSchedules\Pages\ListReportSchedules;
use App\Modules\Core\Filament\Resources\ReportSchedules\Schemas\ReportScheduleForm;
use App\Modules\Core\Filament\Resources\ReportSchedules\Tables\ReportSchedulesTable;
use App\Modules\Core\Models\ReportSchedule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The reports this company sends without being asked — `docs/reports-expansion-plan.md` Phase 8, item 1.
 *
 * **A resource rather than an action on the hub, because a schedule is a thing that has to be edited later.**
 * The recipients change, the timetable changes, and somebody has to be able to switch one off in a hurry — all
 * of which is a list and a form rather than a button on a report. What the hub keeps is the report itself: the
 * state stored here is the state its URL carries, so a schedule *is* a link somebody could have sent.
 *
 * Filed under Settings, with the email wording and the fiscal years, because that is where the things a
 * company configures once and revisits rarely live.
 */
class ReportScheduleResource extends Resource
{
    protected static ?string $model = ReportSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $modelLabel = 'Scheduled report';

    protected static ?string $recordTitleAttribute = 'report_key';

    public static function form(Schema $schema): Schema
    {
        return ReportScheduleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReportSchedulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportSchedules::route('/'),
            'create' => CreateReportSchedule::route('/create'),
            'edit' => EditReportSchedule::route('/{record}/edit'),
        ];
    }
}
