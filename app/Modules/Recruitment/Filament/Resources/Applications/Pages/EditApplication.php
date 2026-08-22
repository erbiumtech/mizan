<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Recruitment\Filament\Resources\Applications\ApplicationResource;
use Filament\Resources\Pages\EditRecord;

class EditApplication extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ApplicationResource::class;
}
