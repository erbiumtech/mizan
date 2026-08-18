<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Commitments\Pages;

use App\Modules\ConstructionCosting\Filament\Resources\Commitments\CommitmentResource;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCommitment extends CreateRecord
{
    protected static string $resource = CommitmentResource::class;

    /**
     * Created through `CommitmentService`, which numbers it in the year's series for its kind.
     *
     * The number matters more here than on most documents: it is what the supplier quotes back on the delivery note
     * and the invoice, so a gap or a reused number is a document that matches nothing.
     */
    protected function handleRecordCreation(array $data): Model
    {
        if (($data['number'] ?? '') === '') {
            unset($data['number']);
        }

        return app(CommitmentService::class)->create(array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    /** Straight to the lines: the job, the cost code and the money all live there. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
