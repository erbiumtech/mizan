<?php

namespace App\Filament\Resources\TableViews\Pages;

use App\Filament\Resources\TableViews\TableViewResource;
use Filament\Resources\Pages\ListRecords;

class ListTableViews extends ListRecords
{
    protected static string $resource = TableViewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
