<?php

namespace App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\ScheduledTransactions\ScheduledTransactionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScheduledTransaction extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ScheduledTransactionResource::class;
}
