<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\ToolboxTalkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListToolboxTalks extends ListRecords
{
    protected static string $resource = ToolboxTalkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-site-personnel', 'Site personnel: Help'),
            CreateAction::make()->label('Record a talk'),
        ];
    }
}
