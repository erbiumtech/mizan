<?php

namespace App\Modules\Accounting\Filament\Resources\ChequeBooks\Pages;

use App\Modules\Accounting\Filament\Resources\ChequeBooks\ChequeBookResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChequeBook extends CreateRecord
{
    protected static string $resource = ChequeBookResource::class;
}
