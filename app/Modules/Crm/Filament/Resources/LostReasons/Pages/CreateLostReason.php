<?php

namespace App\Modules\Crm\Filament\Resources\LostReasons\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\LostReasons\LostReasonResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLostReason extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = LostReasonResource::class;
}
