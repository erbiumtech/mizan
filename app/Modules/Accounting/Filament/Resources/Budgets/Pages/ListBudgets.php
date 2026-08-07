<?php

namespace App\Modules\Accounting\Filament\Resources\Budgets\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\Budgets\BudgetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBudgets extends ListRecords
{
    protected static string $resource = BudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('budgets', 'Budgets: Help'),
            CreateAction::make(),
        ];
    }
}
