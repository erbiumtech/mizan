<?php

namespace App\Modules\Work\Filament\RelationManagers;

use App\Modules\Work\Models\WorkChannelSubscription;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The project page's *Channels* tab — docs/project-management-plan.md §8.7.
 *
 * Contributed to `ProjectResource` through `ResourceContributions`, with the relation added to the
 * Project model from this module's provider, so Projects names nothing of Work's. Each row is one
 * Slack channel or Teams webhook and the events it hears about.
 */
class ChannelSubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'workChannelSubscriptions';

    protected static ?string $title = 'Channels';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return modules()->enabled('work')
            && (auth()->user()?->can('viewAny', WorkChannelSubscription::class) ?? false)
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')
                ->label(__('Posts to'))
                ->options(WorkChannelSubscription::KINDS)
                ->required()
                ->native(false)
                ->live(),

            TextInput::make('destination')
                ->label(fn (Get $get): string => $get('kind') === WorkChannelSubscription::TEAMS ? 'Incoming webhook URL' : 'Channel')
                ->placeholder(fn (Get $get): string => $get('kind') === WorkChannelSubscription::TEAMS ? 'https://…webhook.office.com/…' : '#website-dev')
                ->required()
                ->maxLength(1000)
                ->url(fn (Get $get): bool => $get('kind') === WorkChannelSubscription::TEAMS)
                ->rule(fn (Get $get) => $get('kind') !== WorkChannelSubscription::TEAMS ? null : function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! WorkChannelSubscription::isAllowedTeamsDestination((string) $value)) {
                        $fail('A Teams webhook is an https URL on webhook.office.com or logic.azure.com.');
                    }
                })
                ->helperText(fn (Get $get): string => $get('kind') === WorkChannelSubscription::TEAMS
                    ? 'From the Teams channel\'s connectors. Stored encrypted; it is the whole credential.'
                    : 'The bot must be in the channel. Slack posting needs SLACK_BOT_USER_OAUTH_TOKEN on the server; without it nothing is sent and nothing fails.'),

            CheckboxList::make('events')
                ->label(__('Say when'))
                ->options(WorkChannelSubscription::EVENTS)
                ->required()
                ->columns(2)
                ->default(['item.done', 'sprint.started', 'sprint.completed', 'release.released']),

            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('kind')
            ->columns([
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state): string => WorkChannelSubscription::KINDS[$state] ?? $state),
                TextColumn::make('destination')->label(__('Where'))->state(fn (WorkChannelSubscription $record): string => $record->destinationLabel()),
                TextColumn::make('events')->label(__('Says when'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => WorkChannelSubscription::EVENTS[$state] ?? $state),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('last_posted_at')->label(__('Last post'))->since()->placeholder('never'),
                TextColumn::make('last_failure')->label(__('Last failure'))->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([CreateAction::make()->label(__('Add channel'))])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
