<?php

namespace App\Modules\Accounting\Filament\Resources\Currencies\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Currencies\CurrencyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCurrency extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = CurrencyResource::class;
}
