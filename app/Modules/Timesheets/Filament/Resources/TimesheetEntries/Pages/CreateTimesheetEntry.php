<?php

namespace App\Modules\Timesheets\Filament\Resources\TimesheetEntries\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Timesheets\Filament\Resources\TimesheetEntries\TimesheetEntryResource;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Services\TimesheetService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTimesheetEntry extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = TimesheetEntryResource::class;

    /** Through the service, which refuses a day's worth of minutes typed as hours. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(TimesheetService::class)->book(new TimesheetEntry($data));
    }
}
