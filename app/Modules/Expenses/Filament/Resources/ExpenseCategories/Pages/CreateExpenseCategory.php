<?php

namespace App\Modules\Expenses\Filament\Resources\ExpenseCategories\Pages;

use App\Modules\Expenses\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpenseCategory extends CreateRecord
{
    protected static string $resource = ExpenseCategoryResource::class;
}
