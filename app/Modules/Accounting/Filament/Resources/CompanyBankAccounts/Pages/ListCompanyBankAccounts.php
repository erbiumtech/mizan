<?php

namespace App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanyBankAccounts extends ListRecords
{
    protected static string $resource = CompanyBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('company-bank-accounts', 'Company Bank Accounts: Help'),
            CreateAction::make(),
        ];
    }
}
