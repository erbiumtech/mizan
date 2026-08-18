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
                    // Read from the columns rather than from the relation. The
                    // subject is a morphTo over every audited model in the
                    // application — some of them in a tenant database, some
                    // since deleted — and loading one to print a class name and
                    // an id it already has is a query that can fail for reasons
                    // that have nothing to do with this screen. It is also a
                    // lazy load, which is an exception outside production.
                    ->state(fn (Activity $record): string => filled($record->subject_type)
                        ? class_basename($record->subject_type).' #'.$record->subject_id
                        : '—'),

                TextEntry::make('causer')
                    ->label('Causer')
                    // Eager-loaded by ActivityLogResource::getEloquentQuery(), which
                    // is what resolves this page's record too.
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
