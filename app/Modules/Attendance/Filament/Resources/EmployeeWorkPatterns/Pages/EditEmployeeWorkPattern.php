<?php

namespace App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns\Pages;

use App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns\EmployeeWorkPatternResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeWorkPattern extends EditRecord
{
    protected static string $resource = EmployeeWorkPatternResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
