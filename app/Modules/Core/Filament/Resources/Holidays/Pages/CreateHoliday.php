<?php

namespace App\Modules\Core\Filament\Resources\Holidays\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\Holidays\HolidayResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHoliday extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = HolidayResource::class;
}
