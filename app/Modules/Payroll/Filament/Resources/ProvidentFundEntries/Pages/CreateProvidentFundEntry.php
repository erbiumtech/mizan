<?php

namespace App\Modules\Payroll\Filament\Resources\ProvidentFundEntries\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\ProvidentFundEntries\ProvidentFundEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProvidentFundEntry extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ProvidentFundEntryResource::class;
}
