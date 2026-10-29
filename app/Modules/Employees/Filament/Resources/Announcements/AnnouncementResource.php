<?php

namespace App\Modules\Employees\Filament\Resources\Announcements;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Modules\Employees\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Modules\Employees\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Modules\Employees\Models\Announcement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use InvalidArgumentException;
use UnitEnum;

/**
 * Announcements — docs/saas-launch-plan.md §7.6. HR writes them here; the
 * dashboard shows them to the people they are aimed at.
 */
class AnnouncementResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?int $navigationSort = 90;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(160),
            Textarea::make('body')
                ->required()
                ->rows(8)
                ->helperText('Plain text. A blank line starts a new paragraph in the email.'),
            Select::make('audience_kind')
                ->label('Who it is for')
                ->options([
                    Announcement::AUDIENCE_ALL => 'Everyone',
                    Announcement::AUDIENCE_DEPARTMENT => 'One department',
                    Announcement::AUDIENCE_LOCATION => 'One location',
                ])
                ->default(Announcement::AUDIENCE_ALL)
                ->required()
                ->live(),
            Select::make('audience_value')
                ->label('Department')
                ->options(fn (?Announcement $record): array => options('employees.department', $record?->audience_value))
                ->visible(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_DEPARTMENT)
                ->required(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_DEPARTMENT),
            Select::make('audience_value')
                ->label('Location')
                ->options(fn (): array => \App\Modules\Core\Models\WorkLocation::options())
                ->visible(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_LOCATION)
                ->required(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_LOCATION),
            Toggle::make('pinned')
                ->helperText('Pinned announcements stay at the top of the dashboard while they last.'),
            DatePicker::make('expires_at')
                ->label('Show until')
                ->native(false)
                ->helperText('Leave blank to show it until it is deleted.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->limit(50),
                TextColumn::make('audience')->label('For')->state(fn (Announcement $record): string => $record->audienceLabel()),
                IconColumn::make('pinned')->boolean(),
                TextColumn::make('published_at')->dateTime()->placeholder('Draft')->sortable(),
                TextColumn::make('expires_at')->label('Until')->date()->placeholder('—')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('published_at')
                    ->label('Published')
                    ->nullable()
                    ->trueLabel('Published')
                    ->falseLabel('Drafts'),
            ])
            ->recordActions([
                /**
                 * The act: stamps the time and tells the audience, once. There is no
                 * unpublish — a notice people were told about cannot be untold; delete it
                 * or let it expire.
                 */
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-megaphone')
                    ->color('success')
                    ->visible(fn (Announcement $record): bool => ! $record->isPublished()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Announcement $record): string => 'Tells '.$record->audienceUsers()->count().' people now, by the bell and by email.')
                    ->action(function (Announcement $record): void {
                        try {
                            $told = $record->publish(auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("Published — {$told} people told.")->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
