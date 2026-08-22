<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\ConstructionContracts\Filament\Resources\BackCharges\BackChargeResource;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Services\BackChargeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Creating goes through the service.
 *
 * Not a convenience: the service assigns the reference, copies the job off the contract, computes the total from the
 * cost and the markup, and refuses a receivable or unexecuted contract. A form that wrote the row itself would produce
 * back-charges with no reference and a zero total, and the register's exposure figure would understate by exactly those.
 */
class CreateBackCharge extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BackChargeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $contract = Contract::findOrFail($data['contract_id']);

        return app(BackChargeService::class)->create($contract, $data);
    }
}
