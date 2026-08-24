<?php

namespace App\Modules\ConstructionField\Filament\Resources\Activities\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Filament\Resources\Activities\ActivityResource;
use App\Modules\ConstructionField\Services\ProgrammeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateActivity extends CreateRecord
{
    protected static string $resource = ActivityResource::class;

    /**
     * Through `ProgrammeService::record()`, which refuses an activity with no id or name and the date pairs that are
     * not facts.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(ProgrammeService::class)->record($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
