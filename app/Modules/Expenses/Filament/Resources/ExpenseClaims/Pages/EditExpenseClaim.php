<?php

namespace App\Modules\Expenses\Filament\Resources\ExpenseClaims\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use Filament\Resources\Pages\EditRecord;

class EditExpenseClaim extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
