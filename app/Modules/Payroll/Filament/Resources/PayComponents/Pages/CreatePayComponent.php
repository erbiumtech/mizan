<?php

namespace App\Modules\Payroll\Filament\Resources\PayComponents\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\PayComponents\PayComponentResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayComponent extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PayComponentResource::class;
}
