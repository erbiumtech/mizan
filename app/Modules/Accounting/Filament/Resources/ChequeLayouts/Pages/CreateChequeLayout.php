<?php

namespace App\Modules\Accounting\Filament\Resources\ChequeLayouts\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\ChequeLayouts\ChequeLayoutResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChequeLayout extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ChequeLayoutResource::class;
}
