<?php

namespace App\Modules\Campaigns\Filament\Resources\Segments\Pages;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Campaigns\Filament\Resources\Segments\SegmentResource;
use App\Modules\Campaigns\Models\Segment;
use Filament\Resources\Pages\EditRecord;

class EditSegment extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = SegmentResource::class;

    protected function getHeaderActions(): array
    {
        // Not once a campaign was sent to it — switch it off instead, and the campaign keeps saying who it was for.
        return [ReplicateRecordAction::make(), \Filament\Actions\DeleteAction::make()->visible(fn (Segment $record): bool => ! $record->campaigns()->exists())];
    }
}
