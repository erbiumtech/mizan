<?php

namespace App\Modules\Performance\Filament\Resources\OneToOnes\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Performance\Filament\Resources\OneToOnes\OneToOneResource;
use Filament\Resources\Pages\ListRecords;

class ListOneToOnes extends ListRecords
{
    protected static string $resource = OneToOneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('one-to-ones', 'One-to-ones: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
