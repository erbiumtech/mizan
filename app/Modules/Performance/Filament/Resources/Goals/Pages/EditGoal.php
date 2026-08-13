<?php

namespace App\Modules\Performance\Filament\Resources\Goals\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\Goals\GoalResource;
use Filament\Resources\Pages\EditRecord;

class EditGoal extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = GoalResource::class;
}
