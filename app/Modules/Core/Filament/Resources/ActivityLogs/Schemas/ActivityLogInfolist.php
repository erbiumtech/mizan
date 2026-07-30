<?php

namespace App\Modules\Core\Filament\Resources\ActivityLogs\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

class ActivityLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label('ID'),

                TextEntry::make('log_name')
                    ->label('Model'),

                TextEntry::make('event')
                    ->label('Event')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                        default => (string) $state,
                    }),

                TextEntry::make('description')
                    ->label('Description'),

                TextEntry::make('subject')
                    ->label('Subject')
                    ->state(fn (Activity $record): string => $record->subject
                        ? class_basename($record->subject_type).' #'.$record->subject_id
                        : '—'),

                TextEntry::make('causer')
                    ->label('Causer')
                    ->state(fn (Activity $record): string => $record->causer?->name ?? 'System'),

                TextEntry::make('changes')
                    ->label('Changes')
                    ->state(fn (Activity $record): string => json_encode(
                        $record->changes()?->toArray() ?? [],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    )),

                TextEntry::make('properties')
                    ->label('Extra Properties')
                    ->state(fn (Activity $record): string => json_encode(
                        $record->properties?->toArray() ?? [],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    )),

                TextEntry::make('created_at')
                    ->label('When')
                    ->dateTime(),
            ]);
    }
}
