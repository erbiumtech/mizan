<?php

namespace App\Filament\Resources\SalarySlabs\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\SalarySlabs\SalarySlabResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalarySlab extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = SalarySlabResource::class;
}
