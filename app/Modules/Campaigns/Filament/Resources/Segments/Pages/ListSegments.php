<?php

namespace App\Modules\Campaigns\Filament\Resources\Segments\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Campaigns\Filament\Resources\Segments\SegmentResource;
use Filament\Resources\Pages\ListRecords;

class ListSegments extends ListRecords
{
    protected static string $resource = SegmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('campaign-segments', 'Segments: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
