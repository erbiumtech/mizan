<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Inspections\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\Inspections\InspectionResource;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Modules\ConstructionQhse\Services\InspectionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInspection extends CreateRecord
{
    protected static string $resource = InspectionResource::class;

    /**
     * Through the service, and through the *plan row* where one was chosen.
     *
     * `requestAgainst()` is the path that snapshots the point type and the notice period, and that refuses a plan which
     * is not in force. Creating with a plan row and skipping it would let an inspection cite a draft.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $service = app(InspectionService::class);

        $data = array_filter($data, fn ($value): bool => $value !== null && $value !== '');

        if (! empty($data['itp_activity_id'])) {
            $activity = ItpActivity::query()->findOrFail($data['itp_activity_id']);

            unset($data['itp_activity_id'], $data['job_id'], $data['point_type'], $data['notice_hours']);

            return $service->requestAgainst($activity, $data);
        }

        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return $service->request($job, $data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
