<?php

namespace App\Modules\Employees\Filament\Resources\Announcements;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Modules\Employees\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Modules\Employees\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Modules\Employees\Models\Announcement;
use App\Modules\Employees\Rules\RuleForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                ->label(__('Who it is for'))
                ->options([
                    Announcement::AUDIENCE_ALL => 'Everyone',
                    Announcement::AUDIENCE_DEPARTMENT => 'One department',
                    Announcement::AUDIENCE_LOCATION => 'One location',
                    Announcement::AUDIENCE_RULE => 'By rule',
                    // docs/saas-launch-plan.md §14.10.
                    Announcement::AUDIENCE_ROLE => 'Everybody with one role',
                    Announcement::AUDIENCE_PERSON => 'One person',
                ])
                ->default(Announcement::AUDIENCE_ALL)
                ->required()
                ->live(),
            Select::make('audience_value')
                ->label(__('Department'))
                ->options(fn (?Announcement $record): array => options('employees.department', $record?->audience_value))
                ->visible(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_DEPARTMENT)
                ->required(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_DEPARTMENT),
            Select::make('audience_value')
                ->label(__('Location'))
                ->options(fn (): array => \App\Modules\Core\Models\WorkLocation::options())
                ->visible(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_LOCATION)
                ->required(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_LOCATION),
            Select::make('audience_value')
                ->label(__('Role'))
                ->options(fn (): array => \Spatie\Permission\Models\Role::query()
                    ->where(config('permission.column_names.team_foreign_key', 'company_id'), \Filament\Facades\Filament::getTenant()?->getKey())
                    ->orderBy('name')->pluck('name', 'name')->all())
                ->visible(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_ROLE)
                ->required(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_ROLE),
            Select::make('audience_value')
                ->label(__('Person'))
                ->options(fn (): array => \App\Modules\Employees\Models\Employee::query()->where('is_active', true)->with('user')
                    ->orderBy('employee_id')->get()
                    ->mapWithKeys(fn ($employee): array => [(string) $employee->getKey() => $employee->display_label])->all())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_PERSON)
                ->required(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_PERSON),
            // The audience by rule — docs/saas-launch-plan.md §8.9. Judged when it is published.
            Group::make(RuleForm::schema('audience_rule'))
                ->visible(fn (Get $get): bool => $get('audience_kind') === Announcement::AUDIENCE_RULE)
                ->columnSpanFull(),
            // docs/saas-launch-plan.md §14.10: what kind of notice, and how loudly.
            Select::make('category')
                ->label(__('Category'))
                ->options(fn (?Announcement $record): array => options('employees.announcement_category', $record?->category))
                ->placeholder('None'),
            Select::make('priority')
                ->label(__('Priority'))
                ->options(Announcement::PRIORITIES)
                ->default('normal')
                ->required(),
            Toggle::make('pinned')
                ->helperText('Pinned announcements stay at the top of the dashboard while they last.'),
            /*
             * A date in the future schedules it — §14.10.
             *
             * The dashboard has always respected one; what was missing is that nobody was
             * told when it arrived. `announcements:publish` runs each morning and tells the
             * audience on the first run after the date.
             */
            DateTimePicker::make('published_at')
                ->label(__('Publish at'))
                ->native(false)
                ->seconds(false)
                ->helperText('Leave blank to publish by hand. A date in the future publishes then, and the '
                    .'audience is told that morning.'),
            DatePicker::make('expires_at')
                ->label(__('Show until'))
                ->native(false)
                ->helperText('Leave blank to show it until it is archived.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->limit(50),
                TextColumn::make('audience')->label(__('For'))->state(fn (Announcement $record): string => $record->audienceLabel()),
                TextColumn::make('category')->badge()->color('gray')->placeholder('—')->toggleable(),
                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Announcement::PRIORITIES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger', 'low' => 'gray', default => 'primary'
                    }),
                IconColumn::make('pinned')->boolean(),
                TextColumn::make('published_at')->dateTime()->placeholder('Draft')->sortable(),
                TextColumn::make('expires_at')->label(__('Until'))->date()->placeholder('—')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('published_at')
                    ->label(__('Published'))
                    ->nullable()
                    ->trueLabel('Published')
                    ->falseLabel('Drafts'),

                SelectFilter::make('category')
                    ->options(fn (): array => options('employees.announcement_category')),

                SelectFilter::make('priority')->options(Announcement::PRIORITIES),

                /*
                 * Archived rows are out of the way by default — §14.10.
                 *
                 * The list is the working list; the archive is still here when somebody
                 * asks a year later when the leave policy was announced. Defaulted to "not
                 * archived" rather than filtered out of the resource query, so the archive
                 * is one click away rather than unreachable.
                 */
                TernaryFilter::make('archived_at')
                    ->label(__('Archived'))
                    ->nullable()
                    ->trueLabel('Archived only')
                    ->falseLabel('Not archived')
                    ->placeholder('All')
                    ->default(false),
            ])
            ->recordActions([
                /**
                 * The act: stamps the time and tells the audience, once. There is no
                 * unpublish — a notice people were told about cannot be untold; delete it
                 * or let it expire.
                 */
                Action::make('publish')
                    ->label(fn (Announcement $record): string => $record->isScheduled() ? 'Publish now' : 'Publish')
                    ->icon('heroicon-o-megaphone')
                    ->color('success')
                    ->visible(fn (Announcement $record): bool => ! $record->isPublished()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Announcement $record): string => $record->isScheduled()
                        ? 'Brings it forward to now and tells '.$record->audienceUsers()->count().' people, instead of waiting for '.$record->published_at->toDayDateTimeString().'.'
                        : 'Tells '.$record->audienceUsers()->count().' people now, by the bell and by email.')
                    ->action(function (Announcement $record): void {
                        try {
                            // Pressing it on a scheduled announcement brings it forward,
                            // which is what the button now says it does.
                            $told = $record->publish(auth()->user(), $record->isScheduled() ? now() : null);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title($told === 0
                            ? 'Scheduled. The audience is told on the morning it publishes.'
                            : "Published — {$told} people told.")->send();
                    }),

                /*
                 * Filed away — §14.10. Not a delete and not an expiry: expiring hides it
                 * from the dashboard on a date, and archiving takes it off this list now
                 * and leaves it in the record.
                 */
                Action::make('archive')
                    ->label(fn (Announcement $record): string => $record->isArchived() ? 'Unarchive' : 'Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Announcement $record): string => $record->isArchived()
                        ? 'It comes back onto the working list and, if it has not expired, onto the dashboard.'
                        : 'It comes off this list and off the dashboard. Nobody is told, and nothing is deleted.')
                    ->visible(fn (Announcement $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (Announcement $record) => $record->forceFill(['archived_at' => $record->isArchived() ? null : now()])->save()),
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
