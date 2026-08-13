<?php

namespace App\Modules\Crm\Filament\Resources\NextActions\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\NextActions\NextActionResource;
use Filament\Resources\Pages\ListRecords;

class ListNextActions extends ListRecords
{
    protected static string $resource = NextActionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('next-actions', 'Next Actions: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
