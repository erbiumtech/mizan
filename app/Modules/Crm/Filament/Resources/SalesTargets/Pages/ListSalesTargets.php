<?php

namespace App\Modules\Crm\Filament\Resources\SalesTargets\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\SalesTargets\SalesTargetResource;
use Filament\Resources\Pages\ListRecords;

class ListSalesTargets extends ListRecords
{
    protected static string $resource = SalesTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('sales-targets', 'Sales Targets: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
