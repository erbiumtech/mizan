<?php

namespace App\Modules\Support\Filament\Resources\Tickets\Schemas;

use App\Modules\Support\Models\Ticket;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * A ticket read in the list's view sheet — docs/side-sheets.md. Priority, status and the SLA read
 * with the list's own colours; customer, project and assignee show only when their module is on.
 */
class TicketInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('number')->label(__('Number'))->copyable(),
            TextEntry::make('subject')->label(__('Subject')),
            TextEntry::make('status')
                ->label(__('Status'))
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED => 'success',
                    Ticket::STATUS_PENDING_CUSTOMER => 'warning',
                    Ticket::STATUS_NEW => 'danger',
                    default => 'info',
                })
                ->formatStateUsing(fn (string $state): string => __(ucfirst(str_replace('_', ' ', $state)))),
            TextEntry::make('priority')
                ->label(__('Priority'))
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'urgent' => 'danger',
                    'high' => 'warning',
                    'low' => 'gray',
                    default => 'info',
                })
                ->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextEntry::make('sla')
                ->label(__('SLA'))
                ->state(fn (Ticket $record): string => $record->slaNote() ?? __('Within commitment'))
                ->color(fn (Ticket $record): string => $record->slaNote() ? 'danger' : 'success'),
            TextEntry::make('category.name')->label(__('Category'))->placeholder('—'),
            TextEntry::make('channel')
                ->label(__('Channel'))
                ->formatStateUsing(fn (string $state): string => options('support.ticket_channel', $state)[$state] ?? $state),
            TextEntry::make('contact.name')
                ->label(__('Customer'))
                ->placeholder('—')
                ->visible(fn (): bool => modules()->enabled('invoicing')),
            TextEntry::make('project.name')
                ->label(__('Project'))
                ->placeholder('—')
                ->visible(fn (): bool => modules()->enabled('projects')),
            TextEntry::make('assignee.display_label')
                ->label(__('Assigned to'))
                ->placeholder(__('Unassigned'))
                ->visible(fn (): bool => modules()->enabled('employees')),
            TextEntry::make('opened_at')->label(__('Opened'))->dateTime()->placeholder('—'),
            TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull(),
        ]);
    }
}
