<?php

namespace App\Modules\Accounting\Filament\Resources\Accounts\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Accounts\AccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccount extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = AccountResource::class;
}
