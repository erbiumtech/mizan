<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Workers\Schemas;

use App\Modules\ConstructionCosting\Models\Worker;
use App\Support\EmployeeOptions;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * A worker read in the list's view sheet — docs/side-sheets.md. The employee record and the supplier
 * show only for the engagement that uses them, and only when that module is on, as on the form.
 */
class WorkerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('code')->label(__('Number'))->copyable(),
            TextEntry::make('name')->label(__('Name')),
            TextEntry::make('engagement')
                ->label(__('Engaged as'))
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    Worker::ENGAGEMENT_EMPLOYEE => 'success',
                    Worker::ENGAGEMENT_SUPPLIED => 'info',
                    default => 'gray',
                })
                ->formatStateUsing(fn (string $state): string => __(Worker::ENGAGEMENTS[$state] ?? ucfirst($state))),
            TextEntry::make('trade.name')
                ->label(__('Trade'))
                ->formatStateUsing(fn ($state, Worker $record): string => $record->trade?->displayName() ?? (string) $state)
                ->placeholder('—'),
            TextEntry::make('employee_id')
                ->label(__('Employee record'))
                ->formatStateUsing(fn ($state): string => EmployeeOptions::labelFor($state) ?? (string) $state)
                ->placeholder('—')
                ->visible(fn (Worker $record): bool => modules()->enabled('employees')
                    && $record->engagement === Worker::ENGAGEMENT_EMPLOYEE),
            TextEntry::make('supplier.name')
                ->label(__('Supplied by'))
                ->placeholder('—')
                ->visible(fn (Worker $record): bool => modules()->enabled('invoicing')
                    && $record->engagement === Worker::ENGAGEMENT_SUPPLIED),
            TextEntry::make('national_id')->label(__('ID number'))->placeholder('—'),
            TextEntry::make('phone')->label(__('Phone'))->placeholder('—'),
            TextEntry::make('started_on')->label(__('Started'))->date()->placeholder('—'),
            TextEntry::make('ended_on')->label(__('Left'))->date()->placeholder('—'),
            IconEntry::make('is_active')->label(__('In use'))->boolean(),
            TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
        ]);
    }
}
