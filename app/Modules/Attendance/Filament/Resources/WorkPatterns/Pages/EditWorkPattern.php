<?php

namespace App\Modules\Attendance\Filament\Resources\WorkPatterns\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Attendance\Filament\Resources\WorkPatterns\WorkPatternResource;
use Filament\Resources\Pages\EditRecord;

class EditWorkPattern extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = WorkPatternResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
