<?php

namespace App\Modules\Performance\Filament\Resources\Goals\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\Goals\GoalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGoal extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = GoalResource::class;
}
