<?php

namespace App\Modules\Core\Filament\Resources\WorkLocations;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Core\Filament\Resources\WorkLocations\Pages\CreateWorkLocation;
use App\Modules\Core\Filament\Resources\WorkLocations\Pages\EditWorkLocation;
use App\Modules\Core\Filament\Resources\WorkLocations\Pages\ListWorkLocations;
use App\Modules\Core\Models\WorkLocation;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Branches — docs/saas-launch-plan.md §7.9. A place employees belong to, a
 * holiday may be local to, and a punch may be judged against.
 */
class WorkLocationResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = WorkLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Location';

    protected static ?string $pluralModelLabel = 'Locations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)->unique(ignoreRecord: true),
            TextInput::make('code')->maxLength(20)->helperText('Short, for reports: LHR, KHI, FACTORY.'),
            TextInput::make('address')->maxLength(190),
            TextInput::make('city')->maxLength(80),

            // docs/saas-launch-plan.md §14.10: a branch somebody has to ring, a pin
            // somebody has to find, and which one the letterhead means.
            TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(40),
            TextInput::make('map_url')
                ->label(__('Map link'))
                ->url()
                ->maxLength(255)
                ->helperText('Paste the share link from any map. It is shown as a link, never fetched.'),
            Toggle::make('is_head_office')
                ->label(__('Head office'))
                ->helperText('The branch documents are issued from. Setting it here clears it elsewhere.'),
            Toggle::make('is_active')->default(true),
            Section::make('Geofence')
                ->description('A point and a radius. Leave blank and nothing judges a punch against this location; '
                    .'fill it and the attendance setting "Geofence" decides whether a punch outside it is flagged or refused.')
                ->schema([
                    TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90)->step(0.0000001),
                    TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180)->step(0.0000001),
                    TextInput::make('geofence_radius_m')
                        ->label(__('Radius (metres)'))
                        ->numeric()
                        ->minValue(20)
                        ->maxValue(5000)
                        ->helperText('A phone\'s first fix is often a hundred metres out; under 150 m is a support call waiting to happen.'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->placeholder('—'),
                TextColumn::make('city')->placeholder('—')->sortable(),

                // docs/saas-launch-plan.md §14.10. The flag is a badge rather than a
                // column of mostly-empty ticks.
                TextColumn::make('is_head_office')
                    ->label(__(''))
                    ->badge()
                    ->color('primary')
                    ->state(fn (WorkLocation $record): string => $record->is_head_office ? 'Head office' : '')
                    ->placeholder(''),

                TextColumn::make('phone')->placeholder('—')->toggleable(),
                TextColumn::make('geofence_radius_m')
                    ->label(__('Geofence'))
                    ->state(fn (WorkLocation $record): string => $record->hasGeofence() ? $record->geofence_radius_m.' m' : '—'),
                // Counted on the employees table directly: Core may not name the
                // Employees module, and a count is a count.
                TextColumn::make('employees_count')
                    ->label(__('Employees'))
                    ->state(fn (WorkLocation $record): int => (int) \Illuminate\Support\Facades\DB::connection($record->getConnectionName())
                        ->table('employees')->where('location_id', $record->getKey())->where('is_active', true)->count()),
                IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label(__('Active')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkLocations::route('/'),
            'create' => CreateWorkLocation::route('/create'),
            'edit' => EditWorkLocation::route('/{record}/edit'),
        ];
    }
}
