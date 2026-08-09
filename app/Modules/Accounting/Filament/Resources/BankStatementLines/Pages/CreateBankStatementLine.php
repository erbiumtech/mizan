<?php

namespace App\Modules\Accounting\Filament\Resources\BankStatementLines\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\BankStatementLines\BankStatementLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankStatementLine extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BankStatementLineResource::class;
}
