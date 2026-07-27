<?php

namespace App\Filament\Resources\BankStatementLines\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\BankStatementLines\BankStatementLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankStatementLine extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BankStatementLineResource::class;
}
