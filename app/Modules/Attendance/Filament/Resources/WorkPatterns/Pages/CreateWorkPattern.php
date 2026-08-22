<?php

namespace App\Modules\Attendance\Filament\Resources\WorkPatterns\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Attendance\Filament\Resources\WorkPatterns\WorkPatternResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkPattern extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = WorkPatternResource::class;
}
