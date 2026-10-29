<?php

namespace App\Modules\Accounting\Filament\Resources\BankFileFormats\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\BankFileFormats\BankFileFormatResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankFileFormat extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BankFileFormatResource::class;
}
