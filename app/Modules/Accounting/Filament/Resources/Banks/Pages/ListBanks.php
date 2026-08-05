<?php

namespace App\Modules\Accounting\Filament\Resources\Banks\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\Banks\BankResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBanks extends ListRecords
{
    protected static string $resource = BankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('banks', 'Banks: Help'),
            CreateAction::make(),
        ];
    }
}
