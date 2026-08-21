<?php

namespace App\Modules\ConstructionField\Filament\Resources\PunchLists\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\PunchListResource;
use App\Modules\ConstructionField\Services\PunchListService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePunchList extends CreateRecord
{
    protected static string $resource = PunchListResource::class;

    /** Through `PunchListService::openList()`, which refuses a nameless or kindless list. */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(PunchListService::class)->openList($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
