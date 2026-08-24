<?php

namespace App\Modules\ConstructionField\Filament\Resources\Activities\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\Activities\ActivityResource;
use Filament\Resources\Pages\EditRecord;

class EditActivity extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-programme', 'Programme: Help')];
    }
}
