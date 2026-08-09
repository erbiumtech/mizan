<?php

namespace App\Modules\Mpr\Filament\Resources\MPRs\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Mpr\Filament\Resources\MPRs\MPRResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMPRS extends ListRecords
{
    protected static string $resource = MPRResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('mprs', 'MPRs: Help'),
            CreateAction::make(),
        ];
    }
}
