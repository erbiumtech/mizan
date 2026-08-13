<?php

namespace App\Modules\Performance\Filament\Resources\ReviewCycles\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\ReviewCycles\ReviewCycleResource;
use Filament\Resources\Pages\EditRecord;

class EditReviewCycle extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ReviewCycleResource::class;
}
