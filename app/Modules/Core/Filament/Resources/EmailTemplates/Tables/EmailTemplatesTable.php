<?php

namespace App\Modules\Core\Filament\Resources\EmailTemplates\Tables;

use App\Modules\Core\Models\EmailTemplate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmailTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Email')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subject')->placeholder('shipped wording')->wrap(),

                // Which parts are overridden, so it is obvious at a glance that a
                // template changing only the subject leaves the rest alone.
                TextColumn::make('overrides')
                    ->label('Overrides')
                    ->state(fn (EmailTemplate $record): string => collect([
                        'subject' => $record->subject,
                        'greeting' => $record->greeting,
                        'body' => $record->body,
                        'closing' => $record->closing,
                    ])->filter()->keys()->implode(', ') ?: 'nothing'),

                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->defaultSort('key')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
