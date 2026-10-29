<?php

namespace App\Modules\Leave\Filament\Resources\LeaveGroups\Pages;

use App\Modules\Leave\Filament\Resources\LeaveGroups\LeaveGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLeaveGroup extends EditRecord
{
    protected static string $resource = LeaveGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
