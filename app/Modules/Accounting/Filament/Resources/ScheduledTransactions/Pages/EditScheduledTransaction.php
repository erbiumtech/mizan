<?php

namespace App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\ScheduledTransactions\ScheduledTransactionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditScheduledTransaction extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ScheduledTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
