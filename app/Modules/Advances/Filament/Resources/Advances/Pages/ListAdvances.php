<?php

namespace App\Modules\Advances\Filament\Resources\Advances\Pages;

use App\Modules\Advances\Filament\Resources\Advances\AdvanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdvances extends ListRecords
{
    protected static string $resource = AdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
