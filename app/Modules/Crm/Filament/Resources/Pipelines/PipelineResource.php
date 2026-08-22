<?php

namespace App\Modules\Crm\Filament\Resources\Pipelines;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Crm\Filament\Resources\Pipelines\Pages\CreatePipeline;
use App\Modules\Crm\Filament\Resources\Pipelines\Pages\EditPipeline;
use App\Modules\Crm\Filament\Resources\Pipelines\Pages\ListPipelines;
use App\Modules\Crm\Models\Pipeline;
use BackedEnum;
use Filament\Forms\Components\Repeater;
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
 * The sales process, as rows.
 *
 * Stages are edited here rather than shipped as an enum because every company renames them,
 * and a config array would make a rename a deploy.
 */
class PipelineResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Pipeline::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->placeholder('New business'),

            Toggle::make('is_default')
                ->label('Use for new deals')
                ->helperText('Only one pipeline can be the default; setting this one clears the other.'),

            Toggle::make('is_active')->default(true),

            Repeater::make('stages')
                ->relationship()
                ->label('Stages')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255)->columnSpan(2),

                    TextInput::make('probability_pct')
                        ->label('Likely to close (%)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(0)
                        ->helperText('Weights the forecast, so nobody has to guess twice.'),

                    TextInput::make('rot_after_days')
                        ->label('Chase after (days)')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Blank means this stage never counts as stalled. Terminal stages never do.'),

                    Toggle::make('is_won')
                        ->label('This means won')
                        ->helperText('Marks the terminal stage, so reports never match on a name you might rename.'),

                    Toggle::make('is_lost')->label('This means lost'),
                ])
                ->columns(2)
                ->orderColumn('sort')
                ->defaultItems(0)
                ->addActionLabel('Add a stage')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('stages_count')->label('Stages')->counts('stages')->alignEnd(),
                TextColumn::make('opportunities_count')->label('Deals')->counts('opportunities')->alignEnd(),
                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPipelines::route('/'),
            'create' => CreatePipeline::route('/create'),
            'edit' => EditPipeline::route('/{record}/edit'),
        ];
    }
}
