<?php

namespace App\Filament\Resources\Comments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\Comments\CommentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditComment extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = CommentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
