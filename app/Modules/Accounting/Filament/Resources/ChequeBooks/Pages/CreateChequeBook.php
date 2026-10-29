<?php

namespace App\Modules\Accounting\Filament\Resources\ChequeBooks\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\ChequeBooks\ChequeBookResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChequeBook extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ChequeBookResource::class;
}
