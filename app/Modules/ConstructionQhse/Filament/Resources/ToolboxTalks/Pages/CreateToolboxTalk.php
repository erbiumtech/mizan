<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\ToolboxTalkResource;
use App\Modules\ConstructionQhse\Services\SitePersonnelService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateToolboxTalk extends CreateRecord
{
    protected static string $resource = ToolboxTalkResource::class;

    /** Through the service, which refuses a talk with no topic and one with no time. */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(SitePersonnelService::class)->recordTalk($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
