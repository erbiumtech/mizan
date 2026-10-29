<?php

namespace App\Modules\Payroll\Filament\Resources\ProvidentFundEntries\Pages;

use App\Modules\Payroll\Filament\Resources\ProvidentFundEntries\ProvidentFundEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProvidentFundEntry extends CreateRecord
{
    protected static string $resource = ProvidentFundEntryResource::class;
}
