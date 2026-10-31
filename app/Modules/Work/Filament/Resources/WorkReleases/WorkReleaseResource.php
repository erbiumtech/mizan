<?php

namespace App\Modules\Work\Filament\Resources\WorkReleases;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Resources\WorkReleases\Pages\ListWorkReleases;
use App\Modules\Work\Models\WorkRelease;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Services\CodeHosts;
use App\Modules\Work\Services\Releases;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

/**
 * Releases — docs/project-management-plan.md §6.4.
 *
 * A release is a name and two dates; what is in it is read from the items. Two actions carry the
 * screen: **Release**, which stamps the date and asks about anything still open, and **Notes**,
 * which is the Markdown that goes into the GitHub release and the customer email.
 */
class WorkReleaseResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = WorkRelease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 27;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Releases');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')
                ->label(__('Project'))
                ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->disabledOn('edit'),

            TextInput::make('name')->required()->maxLength(80)->placeholder('2.4.0'),

            DatePicker::make('planned_on')
                ->label(__('Planned for'))
                ->native(false)
                ->helperText('When it is meant to go out. Releasing stamps the date it actually did.'),

            Textarea::make('description')
                ->rows(3)
                ->placeholder('What this version is for, in a sentence or two.')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('project')
                ->withCount([
                    'items',
                    'items as open_items_count' => fn (Builder $q) => $q->whereNull('archived_at')
                        ->whereHas('status', fn (Builder $s) => $s->where('category', '!=', WorkStatus::DONE)),
                ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (WorkRelease $record): ?string => $record->description),

                TextColumn::make('project.name')->label(__('Project'))->toggleable(),

                TextColumn::make('state')
                    ->label(__('State'))
                    ->badge()
                    ->state(fn (WorkRelease $record): string => $record->isReleased() ? 'Released' : 'Planned')
                    ->color(fn (WorkRelease $record): string => $record->isReleased() ? 'success' : 'info'),

                TextColumn::make('planned_on')->label(__('Planned for'))->date('d M Y')->sortable()->placeholder('—'),

                TextColumn::make('released_on')->label(__('Released on'))->date('d M Y')->sortable()->placeholder('—'),

                TextColumn::make('items_count')->label(__('Items'))->alignEnd(),

                // The number that decides whether this can go out quietly.
                TextColumn::make('open_items_count')
                    ->label(__('Still open'))
                    ->alignEnd()
                    ->color(fn (int $state): ?string => $state > 0 ? 'warning' : null),
            ])
            ->defaultSort('planned_on', 'desc')
            ->filters([
                SelectFilter::make('project_id')->label(__('Project'))->relationship('project', 'name'),
                TernaryFilter::make('released')
                    ->label(__('Released'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('released_on'),
                        false: fn (Builder $q) => $q->whereNull('released_on'),
                    ),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (WorkRelease $record): bool => ! $record->isReleased()),

                /*
                 * Shipping it, which is where anything still open is decided.
                 *
                 * The form asks rather than defaulting, because "it moves to the next version" and
                 * "it ships open, behind a flag" are different statements to a customer, and a note
                 * that lists a bug as fixed when it is not is the mistake a customer finds first.
                 */
                Action::make('release')
                    ->label(__('Release'))
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (WorkRelease $record): bool => ! $record->isReleased()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('Stamps today as the release date. Nothing moves and no status changes.')
                    ->schema(fn (WorkRelease $record): array => self::releaseSchema($record))
                    ->action(function (WorkRelease $record, array $data): void {
                        $moving = ($data['open_go'] ?? 'move') === 'move';
                        $target = $moving && filled($data['move_to'] ?? null)
                            ? WorkRelease::query()->find($data['move_to'])
                            : null;

                        try {
                            app(Releases::class)->release(
                                $record,
                                $target,
                                keepOpen: ($data['open_go'] ?? null) === 'keep',
                                // "Move" with no release chosen is the form's "out of any release".
                                clearOpen: $moving && $target === null,
                            );
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('It did not go out')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title($record->name.' is released.')->send();
                    }),

                /*
                 * A GitHub release with the notes as its body — §8.6 — on the repository the
                 * release's items touched most. Only once it has gone out: a release on GitHub
                 * for a version that has not shipped is a changelog ahead of the code.
                 */
                Action::make('publish')
                    ->label(__('GitHub release'))
                    ->icon('heroicon-o-arrow-up-on-square')
                    ->color('gray')
                    ->visible(fn (WorkRelease $record): bool => $record->isReleased()
                        && CodeHosts::anyRegistered()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('Creates a release on the linked repository this version\'s items touched most, with the notes as its body.')
                    ->action(function (WorkRelease $record): void {
                        try {
                            $made = app(CodeHosts::class)->createRelease($record);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not published')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title($record->name.' is on GitHub.')->body($made['url'] ?? '')->send();
                    }),

                Action::make('notes')
                    ->label(__('Notes'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->modalHeading(fn (WorkRelease $record): string => $record->name.': release notes')
                    ->modalDescription('Markdown. One line per finished item, grouped by type — paste it into the GitHub release or the customer email.')
                    ->modalContent(fn (WorkRelease $record): HtmlString => new HtmlString(
                        '<pre class="fi-release-notes">'.e(app(Releases::class)->notes($record)).'</pre>',
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }

    /**
     * What the Release form asks, which depends on whether anything is still open.
     *
     * @return array<int, mixed>
     */
    private static function releaseSchema(WorkRelease $record): array
    {
        $open = app(Releases::class)->open($record)->count();

        if ($open === 0) {
            return [];
        }

        return [
            Radio::make('open_go')
                ->label($open.' item(s) are still open. They')
                ->options([
                    'move' => 'move to another release',
                    'keep' => 'ship open, attributed to this release',
                ])
                ->default('move')
                ->required()
                ->live(),

            Select::make('move_to')
                ->label(__('Which release'))
                ->options(fn (): array => WorkRelease::query()
                    ->where('project_id', $record->project_id)
                    ->unreleased()
                    ->whereKeyNot($record->getKey())
                    ->orderBy('planned_on')
                    ->pluck('name', 'id')
                    ->all())
                ->placeholder('Out of any release')
                ->visible(fn (Get $get): bool => ($get('open_go') ?? 'move') === 'move')
                ->helperText('Leave empty to take them out of any release and back to the plain backlog.'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkReleases::route('/'),
        ];
    }
}
