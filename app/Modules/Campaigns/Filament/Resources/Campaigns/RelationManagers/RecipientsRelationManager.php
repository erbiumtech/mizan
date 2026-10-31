<?php

namespace App\Modules\Campaigns\Filament\Resources\Campaigns\RelationManagers;

use App\Modules\Campaigns\Models\CampaignSend;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Who a campaign reached, one row each — what happened to every recipient and why.
 *
 * Read-only: a send row is the record of what left the building, and nothing about it is edited after
 * the fact. Retrying a failure is the campaign's own action, which puts the row back in the queue.
 */
class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'sends';

    protected static ?string $title = 'Recipients';

    private const STATUSES = [
        CampaignSend::STATUS_PENDING => 'Waiting',
        CampaignSend::STATUS_SENDING => 'Being sent',
        CampaignSend::STATUS_SENT => 'Sent',
        CampaignSend::STATUS_FAILED => 'Failed',
        CampaignSend::STATUS_SKIPPED_NO_CONSENT => 'Skipped — consent',
    ];

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['contact', 'lead']))
            ->columns([
                TextColumn::make('recipient')->label(__('Recipient'))->state(fn (CampaignSend $record): string => $record->recipientLabel()),
                TextColumn::make('to')->label(__('To'))->searchable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        CampaignSend::STATUS_SENT => 'success',
                        CampaignSend::STATUS_FAILED => 'danger',
                        CampaignSend::STATUS_SKIPPED_NO_CONSENT => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (CampaignSend $record): ?string => $record->failed_reason),
                TextColumn::make('sent_at')->label(__('Sent'))->dateTime('d M Y H:i')->placeholder('—')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(self::STATUSES)])
            ->defaultSort('id');
    }
}
