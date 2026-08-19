<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Workers\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\Workers\WorkerResource;
use Filament\Resources\Pages\EditRecord;

class EditWorker extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-labour', 'Trades and workers: Help')];
    }
}
