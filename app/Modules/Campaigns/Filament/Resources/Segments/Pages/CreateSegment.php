<?php

namespace App\Modules\Campaigns\Filament\Resources\Segments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Campaigns\Filament\Resources\Segments\SegmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSegment extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = SegmentResource::class;
}
