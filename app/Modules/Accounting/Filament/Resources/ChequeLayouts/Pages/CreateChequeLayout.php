<?php

namespace App\Modules\Accounting\Filament\Resources\ChequeLayouts\Pages;

use App\Modules\Accounting\Filament\Resources\ChequeLayouts\ChequeLayoutResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChequeLayout extends CreateRecord
{
    protected static string $resource = ChequeLayoutResource::class;
}
