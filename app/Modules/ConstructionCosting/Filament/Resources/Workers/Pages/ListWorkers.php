<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Workers\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\Workers\WorkerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkers extends ListRecords
{
    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-labour', 'Trades and workers: Help'),
            CreateAction::make(),
        ];
    }
}
