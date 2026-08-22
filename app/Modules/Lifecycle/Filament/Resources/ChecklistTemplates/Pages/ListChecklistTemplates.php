<?php

namespace App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates\ChecklistTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListChecklistTemplates extends ListRecords
{
    protected static string $resource = ChecklistTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('checklists', 'Checklists: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
