<?php

namespace App\Filament\Resources\CompanyBankAccounts\Pages;

use App\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanyBankAccounts extends ListRecords
{
    protected static string $resource = CompanyBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
