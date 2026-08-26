<?php

namespace App\Modules\Core\Filament\Resources\ReportSchedules\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\ReportSchedules\ReportScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReportSchedule extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ReportScheduleResource::class;

    /**
     * The owner is whoever created it — `docs/reports-expansion-plan.md` Phase 8, item 2.
     *
     * Set here rather than offered as a field, because the owner is not a preference: it is the person whose
     * access decides what the rows are, and choosing somebody else would be a way to render a report with
     * their permissions. A schedule that should belong to somebody else is one they create.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
