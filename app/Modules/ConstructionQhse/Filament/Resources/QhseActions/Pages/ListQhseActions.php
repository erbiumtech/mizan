<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\QhseActions\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\QhseActions\QhseActionResource;
use Filament\Resources\Pages\ListRecords;

class ListQhseActions extends ListRecords
{
    protected static string $resource = QhseActionResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-qhse-actions', 'QHSE actions: Help')];
    }
}
