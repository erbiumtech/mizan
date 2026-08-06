<?php

namespace App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\PersonalAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePersonalAccount extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PersonalAccountResource::class;
}
