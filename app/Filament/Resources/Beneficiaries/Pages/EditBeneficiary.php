<?php

namespace App\Filament\Resources\Beneficiaries\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Resources\Beneficiaries\BeneficiaryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBeneficiary extends EditRecord
{
    use InteractsWithCustomFields;

    protected static string $resource = BeneficiaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
