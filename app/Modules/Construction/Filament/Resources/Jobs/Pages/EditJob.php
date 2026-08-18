<?php

namespace App\Modules\Construction\Filament\Resources\Jobs\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Construction\Filament\Resources\Jobs\JobResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditJob extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = JobResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
