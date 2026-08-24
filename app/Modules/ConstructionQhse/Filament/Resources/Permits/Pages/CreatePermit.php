<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Permits\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\Permits\PermitResource;
use App\Modules\ConstructionQhse\Services\PermitService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePermit extends CreateRecord
{
    protected static string $resource = PermitResource::class;

    /**
     * Through `PermitService::draft()`, and it is a **draft**: raising a permit is a request, and issuing it is somebody
     * else's decision. A form that created an issued permit would authorise high-risk work as a side effect of typing.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(PermitService::class)->draft($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '' && $value !== [],
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
