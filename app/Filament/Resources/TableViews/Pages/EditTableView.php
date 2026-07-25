<?php

namespace App\Filament\Resources\TableViews\Pages;

use App\Filament\Resources\TableViews\TableViewResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTableView extends EditRecord
{
    protected static string $resource = TableViewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
