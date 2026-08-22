<?php

namespace App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates\Pages\CreateChecklistTemplate;
use App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates\Pages\EditChecklistTemplate;
use App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates\Pages\ListChecklistTemplates;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use BackedEnum;
use Filament\Forms\Components\Repeater;
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
 * Reusable onboarding and exit checklists.
 *
 * Editing a template never changes a run already started: the items are copied when
 * somebody is started on it, so a leaver's list says what they were actually asked to
 * do rather than what the template says today.
 */
class ChecklistTemplateResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ChecklistTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Checklist template';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),

            Select::make('kind')
                ->options([
                    ChecklistTemplate::KIND_ONBOARDING => 'Onboarding',
                    ChecklistTemplate::KIND_EXIT => 'Exit',
                ])
                ->default(ChecklistTemplate::KIND_ONBOARDING)
                ->required()
                ->helperText('Onboarding counts its dates from the joining date; exit from the leaving date.'),

            Toggle::make('is_active')->default(true),

            Repeater::make('items')
                ->relationship()
                ->label('Items')
                ->schema([
                    TextInput::make('title')->required()->maxLength(255)->columnSpan(2),

                    TextInput::make('owner_role')
                        ->label('Owner')
                        ->maxLength(255)
                        ->placeholder('IT')
                        ->helperText('A role, not a person — templates outlive whoever holds the job.'),

                    TextInput::make('due_offset_days')
                        ->label('Days from the date')
                        ->numeric()
                        ->default(0)
                        ->helperText('Negative is before it: -7 is a week before somebody arrives.'),
                ])
                ->columns(2)
                ->orderColumn('sort')
                ->defaultItems(0)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('kind')->badge()->color('gray')->sortable(),
                TextColumn::make('items_count')->label('Items')->counts('items')->alignEnd(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChecklistTemplates::route('/'),
            'create' => CreateChecklistTemplate::route('/create'),
            'edit' => EditChecklistTemplate::route('/{record}/edit'),
        ];
    }
}
