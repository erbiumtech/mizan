<?php

namespace App\Modules\Core\Filament\Resources\Comments\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class CommentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Nova: only Textarea `body` (Comment) — required, alwaysShow.
                // commentable/user/created_at are exceptOnForms; Status/resolver are computed/detail-only.
                Textarea::make('body')
                    ->label('Comment')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }
}
