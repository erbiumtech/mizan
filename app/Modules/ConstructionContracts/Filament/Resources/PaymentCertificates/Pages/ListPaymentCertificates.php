<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\PaymentCertificateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentCertificates extends ListRecords
{
    protected static string $resource = PaymentCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-certificates', 'Claims and certificates: Help'),
            CreateAction::make(),
        ];
    }
}
