<?php

namespace App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompanyBankAccount extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = CompanyBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
