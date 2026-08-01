<?php

namespace App\Modules\Billing\Filament\Resources\BillingRuns\Pages;

use App\Modules\Billing\Filament\Resources\BillingRuns\BillingRunResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBillingRuns extends ListRecords
{
    protected static string $resource = BillingRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
