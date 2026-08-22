<?php

namespace App\Modules\Timesheets\Filament\Resources\TimesheetEntries\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Timesheets\Filament\Resources\TimesheetEntries\TimesheetEntryResource;
use Filament\Resources\Pages\EditRecord;

class EditTimesheetEntry extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = TimesheetEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
