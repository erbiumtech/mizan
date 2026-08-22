<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Itps\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\Itps\ItpResource;
use App\Modules\ConstructionQhse\Services\ItpService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateItp extends CreateRecord
{
    protected static string $resource = ItpResource::class;

    /** Through `ItpService::draft()`, which refuses a plan with no reference or title. */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(ItpService::class)->draft($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
