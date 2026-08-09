<?php

namespace App\Modules\Accounting\Filament\Resources\Loans\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\Loans\LoanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLoans extends ListRecords
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('loans', 'Loans: Help'),
            CreateAction::make(),
        ];
    }
}
