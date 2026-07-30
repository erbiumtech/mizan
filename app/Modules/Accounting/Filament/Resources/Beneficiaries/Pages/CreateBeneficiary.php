<?php

namespace App\Modules\Accounting\Filament\Resources\Beneficiaries\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Beneficiaries\BeneficiaryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBeneficiary extends CreateRecord
{
    use InteractsWithCustomFields, RedirectsToIndex;

    protected static string $resource = BeneficiaryResource::class;
}
