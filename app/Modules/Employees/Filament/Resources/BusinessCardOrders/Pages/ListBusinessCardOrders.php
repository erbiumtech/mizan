<?php

namespace App\Modules\Employees\Filament\Resources\BusinessCardOrders\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\BusinessCardOrders\BusinessCardOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBusinessCardOrders extends ListRecords
{
    protected static string $resource = BusinessCardOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('business-cards', 'Business Cards: Help'),
            CreateAction::make()->label(__('Ask for cards')),
        ];
    }
}
