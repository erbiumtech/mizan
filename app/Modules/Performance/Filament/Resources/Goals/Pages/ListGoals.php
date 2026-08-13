<?php

namespace App\Modules\Performance\Filament\Resources\Goals\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Performance\Filament\Resources\Goals\GoalResource;
use Filament\Resources\Pages\ListRecords;

class ListGoals extends ListRecords
{
    protected static string $resource = GoalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('goals', 'Goals: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
