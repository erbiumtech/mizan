<?php

namespace App\Filament\Resources\CompanyBankAccounts\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompanyBankAccount extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = CompanyBankAccountResource::class;
}
