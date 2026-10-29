<?php

namespace App\Modules\Advances\Filament\Resources\AdvanceTypes\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Advances\Filament\Resources\AdvanceTypes\AdvanceTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdvanceTypes extends ListRecords
{
    protected static string $resource = AdvanceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('advance-types', 'Advance Types: Help'),
            CreateAction::make(),
        ];
    }
}
