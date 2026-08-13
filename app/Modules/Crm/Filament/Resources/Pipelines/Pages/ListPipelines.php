<?php

namespace App\Modules\Crm\Filament\Resources\Pipelines\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\Pipelines\PipelineResource;
use Filament\Resources\Pages\ListRecords;

class ListPipelines extends ListRecords
{
    protected static string $resource = PipelineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('pipelines', 'Pipelines: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
