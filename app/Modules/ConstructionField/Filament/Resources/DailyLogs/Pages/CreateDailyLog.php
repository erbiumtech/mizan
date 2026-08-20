<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\DailyLogResource;
use App\Modules\ConstructionField\Services\DailyLogService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDailyLog extends CreateRecord
{
    protected static string $resource = DailyLogResource::class;

    /**
     * Through the service, which refuses a second diary for a date **with a sentence**.
     *
     * The unique index would refuse it too, but a constraint violation on a site foreman's screen is not an
     * explanation — and "open the existing one and add to it" is the only useful thing to say.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);
        $date = $data['log_date'];

        unset($data['job_id'], $data['log_date']);

        return app(DailyLogService::class)->open($job, $date, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    /** Straight to the children: the manpower, plant and events are where the day's numbers are. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
