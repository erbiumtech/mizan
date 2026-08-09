<?php

namespace App\Modules\Accounting\Filament\Resources\Payments\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\Payments\PaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('payments', 'Payments: Help'),
            CreateAction::make(),
        ];
    }
}
