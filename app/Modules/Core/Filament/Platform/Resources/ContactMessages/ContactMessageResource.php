<?php

namespace App\Modules\Core\Filament\Platform\Resources\ContactMessages;

use App\Modules\Core\Filament\Platform\Resources\ContactMessages\Pages\ListContactMessages;
use App\Modules\Core\Models\ContactMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What visitors wrote through the public contact form — docs/company-website-plan.md W4.
 *
 * Read and mark handled; nothing is typed here. Replies go from the reader's own mailbox, which is
 * where the notification already put the message. Landlord-backed, super-admin only, like every
 * resource on this panel.
 */
class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'contact message';

    protected static ?string $slug = 'contact-messages';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $new = ContactMessage::query()->new()->count();

        return $new > 0 ? (string) $new : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components(static::entries());
    }

    /** @return array<int, TextEntry> */
    public static function entries(): array
    {
        return [
            TextEntry::make('name')->label(__('Name')),
            TextEntry::make('email')->label(__('Email'))->copyable(),
            TextEntry::make('company')->label(__('Company'))->placeholder('—'),
            TextEntry::make('created_at')->label(__('Received'))->dateTime(),
            TextEntry::make('message')->label(__('Message'))->columnSpanFull(),
            TextEntry::make('handler.name')->label(__('Handled by'))->placeholder('—'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('Received'))->since()->sortable(),
                TextColumn::make('name')->label(__('Name'))->searchable()->description(fn (ContactMessage $record): ?string => $record->company),
                TextColumn::make('email')->label(__('Email'))->searchable()->copyable(),
                TextColumn::make('message')->label(__('Message'))->limit(80)->wrap(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => $state === ContactMessage::STATUS_NEW ? 'warning' : 'gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([ContactMessage::STATUS_NEW => __('New'), ContactMessage::STATUS_HANDLED => __('Handled')])
                    ->default(ContactMessage::STATUS_NEW),
            ])
            ->recordActions([
                Action::make('read')
                    ->label(__('Read'))
                    ->icon(Heroicon::OutlinedEnvelopeOpen)
                    ->modalHeading(fn (ContactMessage $record): string => $record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->schema(static::entries()),
                Action::make('handled')
                    ->label(__('Mark handled'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (ContactMessage $record): bool => $record->status === ContactMessage::STATUS_NEW)
                    ->action(fn (ContactMessage $record) => $record->markHandled(auth()->user())),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactMessages::route('/'),
        ];
    }
}
