<?php

namespace App\Modules\Core\Filament\Resources\Holidays\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\Holidays\HolidayResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHoliday extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
