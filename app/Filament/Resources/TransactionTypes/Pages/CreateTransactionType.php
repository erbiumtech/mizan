<?php

namespace App\Filament\Resources\TransactionTypes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\TransactionTypes\TransactionTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransactionType extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = TransactionTypeResource::class;
}
