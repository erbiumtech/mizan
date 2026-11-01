<?php

namespace App\Modules\Performance\Filament\Resources\Goals\Schemas;

use App\Modules\Performance\Models\Goal;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/** A goal read in the list's view sheet — docs/side-sheets.md. */
class GoalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('title')->label(__('Title'))->columnSpanFull(),
            TextEntry::make('employee.display_label')->label(__('Employee'))->placeholder('—'),
            TextEntry::make('cycle.name')->label(__('Cycle'))->placeholder('—'),
            TextEntry::make('status')
                ->label(__('Status'))
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    Goal::STATUS_ACHIEVED => 'success',
                    Goal::STATUS_MISSED => 'danger',
                    Goal::STATUS_DROPPED => 'gray',
                    default => 'info',
                })
                ->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextEntry::make('due_on')->label(__('Due'))->date()->placeholder('—'),
            TextEntry::make('metric')->label(__('Metric'))->placeholder('—'),
            TextEntry::make('weight')->label(__('Weight'))->suffix('%')->placeholder('—'),
            TextEntry::make('target')->label(__('Target'))->placeholder('—'),
            TextEntry::make('actual')->label(__('Actual'))->placeholder('—'),
            TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull(),
        ]);
    }
}
