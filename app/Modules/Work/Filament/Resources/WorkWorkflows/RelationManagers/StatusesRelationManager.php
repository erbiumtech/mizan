<?php

namespace App\Modules\Work\Filament\Resources\WorkWorkflows\RelationManagers;

use App\Modules\Work\Models\WorkStatus;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The statuses of one workflow, and the category each is in.
 *
 * **The category field is the one that matters and the help text says so.** A company naming a
 * status "Waiting on client" has to say whether that counts as work in progress, because every
 * report, filter and board column in the application reads the category and none of them reads the
 * name. Getting it wrong is how an item disappears from "what is still open".
 */
class StatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'statuses';

    protected static ?string $title = 'Statuses';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(60),

            Select::make('category')
                ->label(__('Counts as'))
                ->options(WorkStatus::CATEGORIES)
                ->default(WorkStatus::TODO)
                ->required()
                ->helperText('Every report and filter reads this rather than the name. "In review" and '
                    .'"In QA" are both in progress; "Won\'t do" is done.'),

            TextInput::make('sort')->numeric()->default(0)->label(__('Order'))
                ->helperText('Low to high, left to right on the board.'),

            Select::make('colour')
                ->options(['gray' => 'Grey', 'info' => 'Blue', 'warning' => 'Amber', 'success' => 'Green', 'danger' => 'Red'])
                ->default('gray'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('sort')->label(__('Order'))->alignCenter()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('category')
                    ->label(__('Counts as'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => WorkStatus::CATEGORIES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        WorkStatus::DONE => 'success',
                        WorkStatus::IN_PROGRESS => 'info',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('sort')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
