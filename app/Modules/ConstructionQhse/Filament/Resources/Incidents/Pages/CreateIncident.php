<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Incidents\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\Incidents\IncidentResource;
use App\Modules\ConstructionQhse\Services\IncidentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateIncident extends CreateRecord
{
    protected static string $resource = IncidentResource::class;

    /**
     * Through `IncidentService::report()`, which refuses a missing occurrence time and one in the future, refuses a
     * report earlier than the occurrence, and never infers lost time from a day count.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(IncidentService::class)->report($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
