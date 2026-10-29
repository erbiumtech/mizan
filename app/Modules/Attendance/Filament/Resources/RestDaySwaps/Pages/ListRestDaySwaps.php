<?php

namespace App\Modules\Attendance\Filament\Resources\RestDaySwaps\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Attendance\Filament\Resources\RestDaySwaps\RestDaySwapResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRestDaySwaps extends ListRecords
{
    protected static string $resource = RestDaySwapResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('rest-day-swaps', 'Rest-day Swaps: Help'),
            CreateAction::make(),
        ];
    }
}
