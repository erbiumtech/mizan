<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Variations\Pages;

use App\Modules\ConstructionContracts\Filament\Resources\Variations\VariationResource;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Services\VariationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVariation extends CreateRecord
{
    protected static string $resource = VariationResource::class;

    /**
     * Created through `VariationService`, which numbers it in the contract's own series.
     *
     * The service also refuses a variation against a draft contract, and says to edit the schedule instead —
     * a rule that has to hold for an import and a future API too, not only for this form.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $contract = Contract::query()->findOrFail($data['contract_id']);

        unset($data['contract_id']);

        if (($data['variation_number'] ?? '') === '') {
            unset($data['variation_number']);
        }

        return app(VariationService::class)->create($contract, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    /** Straight to the lines: a variation with no lines cannot be priced from anything. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
