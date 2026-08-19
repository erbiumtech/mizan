<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Pages;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\LabourRecordResource;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Services\LabourRecordService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLabourRecord extends CreateRecord
{
    protected static string $resource = LabourRecordResource::class;

    /**
     * Through `LabourRecordService::record()`, which is where the guards live.
     *
     * Four of them, and none belongs in a form: a heading cost code, a day of negative or no time, a worker who was
     * not engaged on that date, and — the one that matters most — a worker who would end up booked more than
     * twenty-four hours in a day, which is almost always the same sheet entered twice.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $worker = Worker::query()->findOrFail($data['worker_id']);
        $job = Job::query()->findOrFail($data['job_id']);
        $code = CostCode::query()->findOrFail($data['cost_code_id']);

        unset($data['worker_id'], $data['job_id'], $data['cost_code_id']);

        return app(LabourRecordService::class)->record($worker, $job, $code, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
