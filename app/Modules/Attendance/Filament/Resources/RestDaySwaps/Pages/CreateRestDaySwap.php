<?php

namespace App\Modules\Attendance\Filament\Resources\RestDaySwaps\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Attendance\Filament\Resources\RestDaySwaps\RestDaySwapResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRestDaySwap extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = RestDaySwapResource::class;
}
