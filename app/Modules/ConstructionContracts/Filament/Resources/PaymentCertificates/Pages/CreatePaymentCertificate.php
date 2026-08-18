<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages;

use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\PaymentCertificateResource;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ProgressClaim;
use App\Modules\ConstructionContracts\Services\CertificationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePaymentCertificate extends CreateRecord
{
    protected static string $resource = PaymentCertificateResource::class;

    /**
     * Prepared through `CertificationService`, never written by the form.
     *
     * The service numbers it in the contract's series, snapshots the original sum and the **agreed** variations,
     * writes one line per claimable schedule line with the previous certificate's figures frozen into it, and
     * computes the automatic deductions. A form that wrote the row itself would produce a certificate whose
     * bottom line nobody can re-derive.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $contract = Contract::query()->findOrFail($data['contract_id']);
        $claim = ($data['progress_claim_id'] ?? null)
            ? ProgressClaim::query()->find($data['progress_claim_id'])
            : null;

        $certificate = app(CertificationService::class)->prepare($contract, $data['period_end'], $claim);

        if (($data['notes'] ?? null) !== null || ($data['period_start'] ?? null) !== null) {
            $certificate->update(array_filter([
                'notes' => $data['notes'] ?? null,
                'period_start' => $data['period_start'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== ''));
        }

        return $certificate;
    }

    /** Straight to the lines and the deductions, which is what somebody checks before certifying. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
