<?php

namespace App\Modules\ConstructionField\Filament\Resources\Rfis\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Filament\Resources\Rfis\RfiResource;
use App\Modules\ConstructionField\Services\RfiService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRfi extends CreateRecord
{
    protected static string $resource = RfiResource::class;

    /**
     * Through `RfiService::raise()`, which assigns the number.
     *
     * Not in the form: §16.2 numbers the register per job without gaps, and a typed number is how two RFIs come to
     * share one in a register both sides quote from. The service also refuses a blank question — a subject line on its
     * own is something the other side answers with "please clarify", which costs another two weeks.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(RfiService::class)->raise($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
