<?php

namespace App\Modules\ConstructionField\Filament\Resources\DelayEvents\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Filament\Resources\DelayEvents\DelayEventResource;
use App\Modules\ConstructionField\Services\DelayEventService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDelayEvent extends CreateRecord
{
    protected static string $resource = DelayEventResource::class;

    /**
     * Through `DelayEventService::raise()`, which is what computes and freezes the notice date.
     *
     * Not in the form: a due date typed by hand is a due date that can be typed wrong, and this one decides whether a
     * claim survives. The service also refuses a nameless event and one with no cause category — both are rows nobody
     * can assess in six months.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(DelayEventService::class)->raise($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
