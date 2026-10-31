<?php

namespace App\Modules\Performance\Filament\Resources\ScorecardProfiles;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Performance\Filament\Resources\ScorecardProfiles\Pages\CreateScorecardProfile;
use App\Modules\Performance\Filament\Resources\ScorecardProfiles\Pages\EditScorecardProfile;
use App\Modules\Performance\Filament\Resources\ScorecardProfiles\Pages\ListScorecardProfiles;
use App\Modules\Performance\Models\ScorecardProfile;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * How a designation's score is weighted — docs/saas-launch-plan.md §14.10.
 *
 * Per designation and not per person: a company setting it per person would be
 * setting it forty times and arguing about it forty times.
 */
class ScorecardProfileResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ScorecardProfile::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Sheet;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Scorecard Profiles';

    protected static ?string $modelLabel = 'scorecard profile';

    protected static ?int $navigationSort = 66;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('designation')
                ->label(__('Designation'))
                ->options(fn (?ScorecardProfile $record): array => options('employees.designation', $record?->designation))
                ->searchable()
                ->required()
                ->unique(ignoreRecord: true)
                ->helperText('One profile per designation. A designation with none counts everything once.'),

            Toggle::make('is_active')->label(__('In use'))->default(true),

            Section::make('Weights')
                ->description('What each kind of merit entry is worth to this job. One means "as the ledger '
                    .'recorded it"; two counts it double; nought ignores it. Negative points are weighted too — '
                    .'a job where lateness matters more is a job where a late mark costs more.')
                ->columns(2)
                ->schema(array_map(
                    fn (string $label, string $source) => TextInput::make('weights.'.$source)
                        ->label($label)
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(10)
                        ->step(0.5)
                        ->default(ScorecardProfile::DEFAULT_WEIGHT)
                        ->helperText(ScorecardProfile::bucketOf($source) === 'quality' ? 'Counts as quality' : 'Counts as volume'),
                    ScorecardProfile::SOURCES,
                    array_keys(ScorecardProfile::SOURCES),
                ))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('designation')->searchable()->sortable(),
                TextColumn::make('weights')
                    ->label(__('Weights'))
                    ->state(fn (ScorecardProfile $record): string => collect(ScorecardProfile::SOURCES)
                        ->map(fn (string $label, string $source): string => $label.' ×'.$record->weightFor($source))
                        ->implode(' · '))
                    ->wrap(),
                IconColumn::make('is_active')->label(__('In use'))->boolean(),
            ])
            ->defaultSort('designation')
            ->recordActions([EditAction::make(), DeleteAction::make(), ReplicateRecordAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScorecardProfiles::route('/'),
            'create' => CreateScorecardProfile::route('/create'),
            'edit' => EditScorecardProfile::route('/{record}/edit'),
        ];
    }
}
