<?php

namespace App\Modules\Attendance\Filament\Resources\WorkPatterns;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Attendance\Filament\Resources\WorkPatterns\Pages\CreateWorkPattern;
use App\Modules\Attendance\Filament\Resources\WorkPatterns\Pages\EditWorkPattern;
use App\Modules\Attendance\Filament\Resources\WorkPatterns\Pages\ListWorkPatterns;
use App\Modules\Attendance\Models\WorkPattern;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Which days the company works, and how long a day is.
 *
 * `expected_hours` is the field worth care: it is the only place this system records
 * the length of a working day, and phase 3a divides the basic wage by it to work out
 * what an hour of overtime is worth. A wrong figure here is a wrong overtime rate.
 */
class WorkPatternResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = WorkPattern::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Work pattern';

    protected static ?int $navigationSort = 45;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->helperText('e.g. "Office, Mon-Fri" or "Factory, six days".'),

            Toggle::make('is_default')
                ->label('Use for employees with no pattern of their own')
                ->helperText('Only one pattern can be the default; setting this one clears the other.'),

            Repeater::make('days')
                ->relationship()
                ->label('The week')
                ->schema([
                    TextInput::make('weekday')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(7)
                        ->required()
                        ->helperText('1 = Monday … 7 = Sunday'),

                    Toggle::make('is_working')->default(true)->live(),

                    TextInput::make('expected_hours')
                        ->numeric()
                        ->step(0.25)
                        ->minValue(0)
                        ->visible(fn ($get): bool => (bool) $get('is_working'))
                        ->helperText('Overtime is time beyond this, and phase 3a divides the wage by it. Leave blank if the day has no fixed length.'),

                    TimePicker::make('start_time')
                        ->seconds(false)
                        ->visible(fn ($get): bool => (bool) $get('is_working'))
                        ->helperText('Used only to work out lateness, which is recorded and never docked.'),

                    TimePicker::make('end_time')->seconds(false)->visible(fn ($get): bool => (bool) $get('is_working')),
                ])
                ->columns(3)
                ->defaultItems(0)
                ->addActionLabel('Add a weekday')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('days_count')
                    ->label('Working days / week')
                    ->state(fn (WorkPattern $record): int => $record->days->where('is_working', true)->count()),

                IconColumn::make('is_default')->label('Default')->boolean(),

                TextColumn::make('assignments_count')
                    ->label('Employees')
                    ->counts('assignments')
                    ->alignEnd(),
            ])
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkPatterns::route('/'),
            'create' => CreateWorkPattern::route('/create'),
            'edit' => EditWorkPattern::route('/{record}/edit'),
        ];
    }
}
