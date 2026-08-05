<?php

namespace App\Modules\Expenses\Filament\Resources\ExpenseClaims\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpenseClaim extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ExpenseClaimResource::class;

    /**
     * Stamped with whoever is filling the form, not the employee named on it: an
     * approver may not decide a claim they submitted, and that turns on who typed it.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['submitted_by'] = auth()->id();

        return $data;
    }
}
