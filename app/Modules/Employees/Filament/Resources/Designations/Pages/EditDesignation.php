<?php

namespace App\Modules\Employees\Filament\Resources\Designations\Pages;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Filament\Resources\Designations\DesignationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDesignation extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = DesignationResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ReplicateRecordAction::make()];
    }
}
