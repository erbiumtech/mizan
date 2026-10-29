<?php

namespace App\Modules\Accounting\Filament\Resources\BankFileFormats\Pages;

use App\Modules\Accounting\Filament\Resources\BankFileFormats\BankFileFormatResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankFileFormat extends CreateRecord
{
    protected static string $resource = BankFileFormatResource::class;
}
