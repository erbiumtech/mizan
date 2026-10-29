<?php

namespace App\Modules\Performance\Filament\Resources\RecurringTasks\Pages;

use App\Modules\Performance\Filament\Resources\RecurringTasks\RecurringTaskResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringTask extends CreateRecord
{
    protected static string $resource = RecurringTaskResource::class;
}
