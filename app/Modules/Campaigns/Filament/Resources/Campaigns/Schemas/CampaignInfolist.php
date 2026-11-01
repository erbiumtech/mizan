<?php

namespace App\Modules\Campaigns\Filament\Resources\Campaigns\Schemas;

use App\Modules\Campaigns\Models\Campaign;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * A campaign read in the list's view sheet — docs/side-sheets.md. Subject for email, template for
 * WhatsApp, as the form shows them.
 */
class CampaignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('name')->label(__('Name')),
            TextEntry::make('status')
                ->label(__('Status'))
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    Campaign::STATUS_SENT => 'success',
                    Campaign::STATUS_SENDING => 'warning',
                    Campaign::STATUS_PAUSED, Campaign::STATUS_CANCELLED => 'danger',
                    default => 'gray',
                })
                ->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextEntry::make('channel')
                ->label(__('Channel'))
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    Campaign::CHANNEL_WHATSAPP => 'WhatsApp',
                    default => __('Email'),
                }),
            TextEntry::make('segment.name')->label(__('Send to'))->placeholder('—'),
            TextEntry::make('subject')
                ->label(__('Subject line'))
                ->placeholder('—')
                ->visible(fn (Campaign $record): bool => $record->channel === Campaign::CHANNEL_EMAIL),
            TextEntry::make('template_name')
                ->label(__('Approved template'))
                ->placeholder('—')
                ->visible(fn (Campaign $record): bool => $record->channel === Campaign::CHANNEL_WHATSAPP),
            TextEntry::make('scheduled_at')->label(__('Scheduled for'))->dateTime()->placeholder('—'),
            TextEntry::make('sent_at')->label(__('Sent'))->dateTime()->placeholder('—'),
            TextEntry::make('body')->label(__('Body'))->placeholder('—')->columnSpanFull(),
        ]);
    }
}
