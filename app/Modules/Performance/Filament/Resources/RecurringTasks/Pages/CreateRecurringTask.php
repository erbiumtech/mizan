<?php

namespace App\Modules\Performance\Filament\Resources\RecurringTasks\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\RecurringTasks\RecurringTaskResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringTask extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = RecurringTaskResource::class;
}
