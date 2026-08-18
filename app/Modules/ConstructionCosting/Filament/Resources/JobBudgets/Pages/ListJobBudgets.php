<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\JobBudgetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJobBudgets extends ListRecords
{
    protected static string $resource = JobBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-budget', 'Job budget: Help'),
            CreateAction::make(),
        ];
    }
}
