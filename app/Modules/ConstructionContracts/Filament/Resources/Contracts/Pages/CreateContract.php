<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Contracts\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\ContractResource;
use App\Modules\ConstructionContracts\Services\ContractService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateContract extends CreateRecord
{
    protected static string $resource = ContractResource::class;

    /**
     * Created through `ContractService`, not by the form.
     *
     * The service numbers the contract and seeds the terms the job captured at tender — retention, advance,
     * damages, the dates — so a contract raised on this screen carries the same figures as one raised by an
     * import. Whatever the form supplied wins over the seed, which is what makes the fields editable here
     * without the seeding being a surprise.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        unset($data['job_id']);

        // A blank number means "give me the next one in the job's series".
        if (($data['contract_number'] ?? '') === '') {
            unset($data['contract_number']);
        }

        return app(ContractService::class)->create($job, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    /**
     * Straight to the schedule, which is the next thing anybody does.
     *
     * A contract with no lines cannot be executed, so the register would only be somewhere to click "edit".
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
