<?php

namespace App\Modules\Leave\Filament\Resources\LeaveTypes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Leave\Filament\Resources\LeaveTypes\LeaveTypeResource;
use Filament\Resources\Pages\EditRecord;

class EditLeaveType extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = LeaveTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
