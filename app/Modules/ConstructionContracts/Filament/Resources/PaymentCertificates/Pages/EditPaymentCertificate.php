<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\PaymentCertificateResource;
use Filament\Resources\Pages\EditRecord;

class EditPaymentCertificate extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PaymentCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-certificates', 'Claims and certificates: Help')];
    }
}
