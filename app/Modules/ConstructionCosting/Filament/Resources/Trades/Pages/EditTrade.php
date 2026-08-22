<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Trades\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\Trades\TradeResource;
use Filament\Resources\Pages\EditRecord;

class EditTrade extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = TradeResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-labour', 'Trades and workers: Help')];
    }
}
