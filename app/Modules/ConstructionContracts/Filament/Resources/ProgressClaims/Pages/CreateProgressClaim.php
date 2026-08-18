<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Pages;

use App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\ProgressClaimResource;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Services\CertificationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProgressClaim extends CreateRecord
{
    protected static string $resource = ProgressClaimResource::class;

    /**
     * Opened through `CertificationService`, which numbers it and **seeds its lines from the last claim**.
     *
     * A claim is cumulative: last month's line values are the floor for this month's, and starting from a blank
     * sheet is how a claim comes to certify less than the one before it.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $contract = Contract::query()->findOrFail($data['contract_id']);

        $claim = app(CertificationService::class)->openClaim(
            $contract,
            $data['period_end'],
            $data['period_start'] ?? null,
        );

        $claim->update(array_filter([
            'claim_number' => $data['claim_number'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== ''));

        return $claim;
    }

    /** Straight to the lines, which is the whole of the work. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
