<?php

namespace App\Modules\Accounting\Filament\Resources\Beneficiaries\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\Beneficiaries\BeneficiaryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBeneficiaries extends ListRecords
{
    protected static string $resource = BeneficiaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('beneficiaries', 'Beneficiaries: Help'),
            CreateAction::make(),
        ];
    }
}
