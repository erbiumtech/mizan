<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\Ncrs\NcrResource;
use App\Modules\ConstructionQhse\Services\NcrService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateNcr extends CreateRecord
{
    protected static string $resource = NcrResource::class;

    /**
     * Through `NcrService::raise()`, which numbers it and **drops any disposition that arrived with the form**.
     *
     * §17.2 calls disposition "the field that decides whether money changes hands", so it is a deliberate later act
     * rather than something settled before anybody has looked at the work.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(NcrService::class)->raise($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
