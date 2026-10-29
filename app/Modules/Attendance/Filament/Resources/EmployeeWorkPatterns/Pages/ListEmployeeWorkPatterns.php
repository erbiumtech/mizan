<?php

namespace App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns\EmployeeWorkPatternResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeWorkPatterns extends ListRecords
{
    protected static string $resource = EmployeeWorkPatternResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('pattern-assignments', 'Pattern Assignments: Help'),
            CreateAction::make(),
        ];
    }
}
