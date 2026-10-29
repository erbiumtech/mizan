<?php

namespace App\Modules\Lifecycle\Filament\Resources\Separations\RelationManagers;

use App\Modules\Lifecycle\Models\ClearanceItem;
use App\Modules\Lifecycle\Services\Separations;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The clearance list: auto items from the records, manual ones by hand; each cleared or waived. */
class ClearanceItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Clearance';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(190),
            TextInput::make('amount')->numeric()->nullable(),
            TextInput::make('note')->maxLength(190),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('source')->badge()->color('gray')->placeholder('manual'),
                TextColumn::make('amount')->numeric(2)->placeholder('—')->alignRight(),
                TextColumn::make('state')->label('State')->state(fn (ClearanceItem $i): string => match (true) {
                    $i->cleared_at !== null => 'cleared',
                    $i->waived_at !== null => 'waived: '.$i->waive_reason,
                    default => 'open',
                })->color(fn (ClearanceItem $i): string => $i->isClear() ? 'success' : 'warning'),
            ])
            ->headerActions([CreateAction::make()->label('Add an item')->mutateDataUsing(fn (array $data): array => $data + ['kind' => ClearanceItem::KIND_MANUAL])])
            ->recordActions([
                Action::make('clear')->label('Clear')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (ClearanceItem $i): bool => ! $i->isClear())
                    ->action(function (ClearanceItem $record): void {
                        app(Separations::class)->clear($record, auth()->user());
                        Notification::make()->success()->title('Cleared.')->send();
                    }),
                Action::make('waive')->label('Waive')->icon('heroicon-o-hand-raised')->color('gray')
                    ->visible(fn (ClearanceItem $i): bool => ! $i->isClear())
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(function (ClearanceItem $record, array $data): void {
                        app(Separations::class)->waive($record, auth()->user(), $data['reason']);
                        Notification::make()->success()->title('Waived.')->send();
                    }),
            ]);
    }
}
