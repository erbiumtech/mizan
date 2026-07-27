<?php

namespace App\Filament\Resources\Banks\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\Banks\BankResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBank extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BankResource::class;
}
