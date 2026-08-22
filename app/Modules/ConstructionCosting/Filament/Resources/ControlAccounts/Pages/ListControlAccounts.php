<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\ControlAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListControlAccounts extends ListRecords
{
    protected static string $resource = ControlAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-reconciliation', 'Control accounts: Help'),
            CreateAction::make(),
        ];
    }
}
