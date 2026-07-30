<?php

namespace App\Modules\Accounting\Filament\Resources\TransactionTypes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\TransactionTypes\TransactionTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTransactionType extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = TransactionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
