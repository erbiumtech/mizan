<?php

namespace App\Modules\Performance\Filament\Resources\RecurringTasks\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\RecurringTasks\RecurringTaskResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecurringTask extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = RecurringTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
