<?php

namespace App\Modules\Core\Filament\Resources\OptionValues\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\OptionValues\OptionValueResource;
use Filament\Resources\Pages\ListRecords;

class ListOptionValues extends ListRecords
{
    protected static string $resource = OptionValueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('dropdown-options', 'Dropdown Options: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
