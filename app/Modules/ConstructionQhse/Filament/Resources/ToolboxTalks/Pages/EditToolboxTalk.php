<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\ToolboxTalkResource;
use Filament\Resources\Pages\EditRecord;

class EditToolboxTalk extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ToolboxTalkResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-site-personnel', 'Site personnel: Help')];
    }
}
