<?php

namespace App\Modules\Employees\Filament\Resources\LetterRequests\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Filament\Resources\LetterRequests\LetterRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLetterRequest extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = LetterRequestResource::class;
}
