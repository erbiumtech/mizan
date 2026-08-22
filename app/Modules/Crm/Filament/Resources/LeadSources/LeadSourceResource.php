<?php

namespace App\Modules\Crm\Filament\Resources\LeadSources;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Crm\Filament\Resources\LeadSources\Pages\CreateLeadSource;
use App\Modules\Crm\Filament\Resources\LeadSources\Pages\EditLeadSource;
use App\Modules\Crm\Filament\Resources\LeadSources\Pages\ListLeadSources;
use App\Modules\Crm\Models\LeadSource;
use BackedEnum;
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
 * Reference data. Small enough that the form and table live here rather than in
 * their own Schemas/ and Tables/ classes — three fields do not earn two files.
 */
class LeadSourceResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = LeadSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Lead source';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('One row per channel. Two rows meaning the same thing split its win rate in half and nothing reports that they did.'),

            Toggle::make('is_active')
                ->default(true)
                ->helperText('Switch off rather than delete: a source with leads against it keeps their history.'),

            TextInput::make('sort')->label('Order in lists')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('leads_count')->label('Leads')->counts('leads')->alignEnd()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
            ])
            ->defaultSort('sort')
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeadSources::route('/'),
            'create' => CreateLeadSource::route('/create'),
            'edit' => EditLeadSource::route('/{record}/edit'),
        ];
    }
}
