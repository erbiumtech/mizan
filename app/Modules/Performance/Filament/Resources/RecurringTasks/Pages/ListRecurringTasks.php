<?php

namespace App\Modules\Performance\Filament\Resources\RecurringTasks\Pages;

use App\Modules\Performance\Filament\Resources\RecurringTasks\RecurringTaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecurringTasks extends ListRecords
{
    protected static string $resource = RecurringTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
