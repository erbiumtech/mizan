<?php

namespace App\Modules\Timesheets\Filament\Resources\TimesheetEntries\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Timesheets\Filament\Resources\TimesheetEntries\TimesheetEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListTimesheetEntries extends ListRecords
{
    protected static string $resource = TimesheetEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('timesheets', 'Timesheets: Help'),
            \Filament\Actions\CreateAction::make()->label('Book time'),
        ];
    }
}
