<?php

namespace App\Modules\Accounting\Filament\Resources\Payments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayment extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PaymentResource::class;
}
