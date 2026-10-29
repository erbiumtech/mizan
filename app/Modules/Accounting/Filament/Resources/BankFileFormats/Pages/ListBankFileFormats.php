<?php

namespace App\Modules\Accounting\Filament\Resources\BankFileFormats\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\BankFileFormats\BankFileFormatResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankFileFormats extends ListRecords
{
    protected static string $resource = BankFileFormatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('bank-file-formats', 'Bank File Formats: Help'),
            CreateAction::make(),
        ];
    }
}
