<?php

namespace App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Pages;

use App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\PersonalAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePersonalAccount extends CreateRecord
{
    protected static string $resource = PersonalAccountResource::class;
}
