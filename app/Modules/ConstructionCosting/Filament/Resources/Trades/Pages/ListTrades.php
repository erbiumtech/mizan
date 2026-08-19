<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Trades\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\Trades\TradeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrades extends ListRecords
{
    protected static string $resource = TradeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-labour', 'Trades and workers: Help'),
            CreateAction::make(),
        ];
    }
}
