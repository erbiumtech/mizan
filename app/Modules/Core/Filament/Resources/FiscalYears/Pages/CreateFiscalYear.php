<?php

namespace App\Modules\Core\Filament\Resources\FiscalYears\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\FiscalYears\FiscalYearResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFiscalYear extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = FiscalYearResource::class;
}
