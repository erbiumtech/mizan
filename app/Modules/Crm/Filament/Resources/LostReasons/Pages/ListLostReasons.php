<?php

namespace App\Modules\Crm\Filament\Resources\LostReasons\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\LostReasons\LostReasonResource;
use Filament\Resources\Pages\ListRecords;

class ListLostReasons extends ListRecords
{
    protected static string $resource = LostReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('lost-reasons', 'Lost Reasons: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
