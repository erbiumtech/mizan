<?php

namespace App\Modules\Employees\Filament\Resources\Designations\Pages;

use App\Modules\Employees\Filament\Resources\Designations\DesignationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDesignation extends EditRecord
{
    protected static string $resource = DesignationResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
