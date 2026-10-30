<?php

namespace App\Modules\Employees\Filament\Resources\BusinessCardOrders\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Filament\Resources\BusinessCardOrders\BusinessCardOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBusinessCardOrder extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BusinessCardOrderResource::class;
}
