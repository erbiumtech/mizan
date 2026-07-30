<?php

namespace App\Modules\Core\Filament\Resources\TableViews\Pages;

use App\Modules\Core\Filament\Resources\TableViews\TableViewResource;
use Filament\Resources\Pages\ListRecords;

class ListTableViews extends ListRecords
{
    protected static string $resource = TableViewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
