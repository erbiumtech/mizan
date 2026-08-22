<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\RequisitionResource;
use App\Modules\ConstructionCosting\Services\RequisitionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRequisition extends CreateRecord
{
    protected static string $resource = RequisitionResource::class;

    /**
     * Raised through `RequisitionService`, which numbers it and records who asked.
     *
     * Who asked matters more here than on most documents: a requisition is the one construction register site staff
     * create in, and "who wanted this" is the first question when the delivery turns up and nobody knows why.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        return app(RequisitionService::class)->create($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    /** Straight to the lines: the request is the lines. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
