<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\SitePersonnelResource;
use App\Modules\ConstructionQhse\Services\SitePersonnelService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSitePersonnel extends CreateRecord
{
    protected static string $resource = SitePersonnelResource::class;

    /** Through the service, which asks for a name and nothing else. */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(SitePersonnelService::class)->register($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
