<?php

namespace App\Modules\Work\Filament\Resources\WorkWorkflows\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Work\Filament\Resources\WorkWorkflows\WorkWorkflowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkWorkflows extends ListRecords
{
    protected static string $resource = WorkWorkflowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            HelpAction::make('work-workflows', 'Workflows: Help'),
        ];
    }
}
