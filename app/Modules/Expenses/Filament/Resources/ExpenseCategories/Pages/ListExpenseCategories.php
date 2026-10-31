<?php

namespace App\Modules\Expenses\Filament\Resources\ExpenseCategories\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Expenses\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseCategories extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = ExpenseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('expense-categories', 'Expense Categories: Help'),
            CreateAction::make(),
        ];
    }
}
