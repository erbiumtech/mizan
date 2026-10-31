<?php

namespace App\Modules\Expenses\Filament\Resources\ExpenseCategories\Pages;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Expenses\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExpenseCategory extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ExpenseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ReplicateRecordAction::make()];
    }
}
