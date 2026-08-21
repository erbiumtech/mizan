<?php

namespace App\Modules\ConstructionField\Filament\Resources\Submittals\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Filament\Resources\Submittals\SubmittalResource;
use App\Modules\ConstructionField\Services\SubmittalService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSubmittal extends CreateRecord
{
    protected static string $resource = SubmittalResource::class;

    /**
     * Through `SubmittalService::register()`.
     *
     * The service refuses a submittal with no specification section or no title — the register is organised by the one
     * and read by the other, and a row missing either is a row nobody finds when the item is needed.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(SubmittalService::class)->register($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
