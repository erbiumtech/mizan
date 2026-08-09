<?php

namespace App\Modules\Accounting\Filament\Resources\TransactionTypes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\TransactionTypes\TransactionTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransactionType extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = TransactionTypeResource::class;
}
