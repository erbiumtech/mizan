<?php

namespace App\Modules\Attendance\Filament\Resources\WorkPatterns\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Attendance\Filament\Resources\WorkPatterns\WorkPatternResource;
use Filament\Resources\Pages\ListRecords;

class ListWorkPatterns extends ListRecords
{
    protected static string $resource = WorkPatternResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('work-patterns', 'Work Patterns: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
