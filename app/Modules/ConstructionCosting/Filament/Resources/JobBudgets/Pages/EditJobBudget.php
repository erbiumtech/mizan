<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\JobBudgetResource;
use Filament\Resources\Pages\EditRecord;

class EditJobBudget extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = JobBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-budget', 'Job budget: Help')];
    }
}
