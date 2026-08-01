<?php

namespace App\Modules\Accounting\Filament\Resources\Banks\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Banks\BankResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBank extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BankResource::class;
}
