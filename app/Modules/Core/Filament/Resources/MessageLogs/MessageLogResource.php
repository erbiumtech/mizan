<?php

namespace App\Modules\Core\Filament\Resources\MessageLogs;

use App\Modules\Core\Filament\Resources\MessageLogs\Pages\ListMessageLogs;
use App\Modules\Core\Models\MessageLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What the application sent, and whether it arrived — docs/saas-launch-plan.md §14.7.
 *
 * Read-only on purpose, and not only because a log one can edit is not a log: the
 * question people bring here is "the employee says they never got their payslip",
 * and the answer is a row showing the address it went to. Nothing to do but look.
 */
class MessageLogResource extends Resource
{
    protected static ?string $model = MessageLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Audit & Taxes';

    protected static ?string $modelLabel = 'Message';

    protected static ?string $pluralModelLabel = 'Message Log';

    protected static ?int $navigationSort = 40;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sent_at')->label('Sent')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('channel')->badge()
                    ->color(fn (string $state): string => $state === MessageLog::CHANNEL_WHATSAPP ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state): string => $state === MessageLog::CHANNEL_WHATSAPP ? 'WhatsApp' : 'Email'),
                TextColumn::make('recipient')->searchable()->wrap(),
                TextColumn::make('purpose')->label('What')->placeholder('—')->searchable(),
                TextColumn::make('subject')->limit(60)->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => $state === MessageLog::STATUS_FAILED ? 'danger' : 'success')
                    // The error only on the row that failed, where it is the whole point.
                    ->description(fn (MessageLog $record): ?string => $record->error),
            ])
            ->defaultSort('sent_at', 'desc')
            ->filters([
                SelectFilter::make('channel')->options(['mail' => 'Email', 'whatsapp' => 'WhatsApp']),
                Filter::make('failed')
                    ->label('Failed only')
                    ->query(fn (Builder $query): Builder => $query->failed()),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMessageLogs::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
