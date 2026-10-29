<?php

namespace App\Modules\Payroll\Filament\Resources\PayAdjustments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\PayAdjustments\PayAdjustmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayAdjustment extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PayAdjustmentResource::class;
}
