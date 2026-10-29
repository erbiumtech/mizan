<?php

namespace App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns\EmployeeWorkPatternResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeWorkPattern extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = EmployeeWorkPatternResource::class;
}
