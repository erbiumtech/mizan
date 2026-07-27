<?php

namespace App\Filament\Resources\TableViews\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\TableViews\TableViewResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTableView extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = TableViewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
