<?php

namespace App\Modules\Performance\Filament\Resources\Reviews\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\Reviews\ReviewResource;
use Filament\Resources\Pages\EditRecord;

class EditReview extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ReviewResource::class;
}
