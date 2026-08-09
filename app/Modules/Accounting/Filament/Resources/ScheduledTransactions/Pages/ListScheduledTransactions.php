<?php

namespace App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\ScheduledTransactions\ScheduledTransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScheduledTransactions extends ListRecords
{
    protected static string $resource = ScheduledTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('scheduled-transactions', 'Scheduled Entries: Help'),
            CreateAction::make(),
        ];
    }
}
