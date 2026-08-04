<?php

namespace App\Modules\Expenses\Filament\Resources\ExpenseClaims\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListExpenseClaims extends ListRecords
{
    protected static string $resource = ExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
