<?php

namespace App\Modules\Work\Filament\Resources\WorkWorkflows;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Work\Filament\Resources\WorkWorkflows\Pages\ListWorkWorkflows;
use App\Modules\Work\Filament\Resources\WorkWorkflows\RelationManagers\StatusesRelationManager;
use App\Modules\Work\Models\WorkWorkflow;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The statuses a board may have, and what moving between them requires — §4.
 *
 * Filed under Settings and gated on `WorkflowManage` because a workflow governs every board in the
 * company: changing one is not a board manager's decision, it is the company's.
 */
class WorkWorkflowResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = WorkWorkflow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 36;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Workflows');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80),
            TextInput::make('description')->maxLength(255)
                ->helperText('What kind of work this one is for — "Bugs", "Client approvals".'),
            Toggle::make('is_default')
                ->label(__('Use this for projects that have not chosen one'))
                ->helperText('Exactly one workflow should carry this. A project may still pick another.'),

            // Where a pull request being opened moves an item — docs/project-management-plan.md §8.4.
            // Empty means the status named "In review", if there is one; nothing happens otherwise.
            Select::make('review_status_id')
                ->label(__('A pull request moves items to'))
                ->options(fn (?WorkWorkflow $record): array => $record === null ? [] : $record->statuses()->pluck('name', 'id')->all())
                ->placeholder('The status named "In review", if any')
                ->visibleOn('edit')
                ->helperText('Only statuses of this workflow. Three categories are kept on purpose — a review is in progress by any honest reading — so this names which in-progress status it is.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('description')->placeholder('—')->wrap(),
                TextColumn::make('statuses_count')->counts('statuses')->label(__('Statuses'))->alignEnd(),
                TextColumn::make('rules_count')->counts('rules')->label(__('Rules'))->alignEnd(),
                IconColumn::make('is_default')->boolean()->label(__('Default')),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            StatusesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkWorkflows::route('/'),
        ];
    }
}
