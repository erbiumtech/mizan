<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Inspections\RelationManagers;

use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\InspectionCheck;
use App\Modules\ConstructionQhse\Services\InspectionService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The check sheet — §17.1.
 *
 * **Expected against actual, with a unit.** "Concrete cube at 28 days" is a number somebody compares with a standard; a
 * paragraph saying it looked fine is not a record, and the difference is what a certification body reads.
 *
 * **Pass is a three-state field, not a checkbox.** A check sheet is filled in as the inspection proceeds, and a boolean
 * default would make every line nobody has reached yet read as a failure — an inspection that failed on lines nobody
 * looked at is worse than no record at all.
 */
class ChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'checks';

    protected static ?string $title = 'Check sheet';

    private function inspection(): Inspection
    {
        /** @var Inspection $inspection */
        $inspection = $this->getOwnerRecord();

        return $inspection;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('sequence')->numeric()->default(0),

                Select::make('passed')
                    ->label('Result')
                    ->options([1 => 'Pass', 0 => 'Fail'])
                    ->placeholder('Not yet checked')
                    ->helperText('Left blank means nobody has reached this line — which is a different fact from failing it.'),

                Textarea::make('description')->required()->rows(2)->columnSpanFull(),

                TextInput::make('expected_value')->maxLength(255),
                TextInput::make('actual_value')->maxLength(255),
                TextInput::make('unit')->maxLength(32)->helperText('N/mm², mm, °C.'),

                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('sequence')
            ->columns([
                TextColumn::make('sequence')->label('#')->sortable(),

                TextColumn::make('description')->wrap()->limit(60),

                TextColumn::make('values')
                    ->label('Expected / measured')
                    ->getStateUsing(fn (InspectionCheck $record): ?string => $record->describe() ?: null)
                    ->placeholder('—'),

                TextColumn::make('passed')
                    ->label('Result')
                    ->badge()
                    ->getStateUsing(fn (InspectionCheck $record): string => match (true) {
                        $record->isOutstanding() => 'not checked',
                        $record->hasFailed() => 'fail',
                        default => 'pass',
                    })
                    ->color(fn (InspectionCheck $record): string => match (true) {
                        $record->isOutstanding() => 'gray',
                        $record->hasFailed() => 'danger',
                        default => 'success',
                    }),

                TextColumn::make('notes')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('ConstructionInspectionUpdate') ?? false)
                    ->using(fn (array $data): Model => app(InspectionService::class)
                        ->addCheck($this->inspection(), $data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('ConstructionInspectionUpdate') ?? false),
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('ConstructionInspectionUpdate') ?? false),
            ])
            ->emptyStateHeading('No checks recorded')
            ->emptyStateDescription('A measured value against an expected one is a record. A paragraph saying it looked fine is not.');
    }
}
