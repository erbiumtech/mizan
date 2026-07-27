<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayment extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PaymentResource::class;
}
