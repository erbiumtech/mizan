<?php

namespace App\Modules\Core\Filament\Resources\Holidays\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\Holidays\HolidayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHolidays extends ListRecords
{
    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('holidays', 'Holidays: Help'),
            CreateAction::make(),
        ];
    }
}
