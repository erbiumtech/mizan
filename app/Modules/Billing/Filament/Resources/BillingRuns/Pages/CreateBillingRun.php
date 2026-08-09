<?php

namespace App\Modules\Billing\Filament\Resources\BillingRuns\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Billing\Filament\Resources\BillingRuns\BillingRunResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBillingRun extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BillingRunResource::class;
}
