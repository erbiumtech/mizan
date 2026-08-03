<?php

namespace App\Modules\Accounting\Filament\Resources\BankStatements\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\BankStatements\BankStatementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankStatement extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BankStatementResource::class;
}
