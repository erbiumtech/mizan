<?php

namespace App\Modules\Performance\Filament\Resources\ReviewTemplates;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\BelongsToModule;
use App\Modules\Performance\Filament\Resources\ReviewTemplates\Pages\CreateReviewTemplate;
use App\Modules\Performance\Filament\Resources\ReviewTemplates\Pages\EditReviewTemplate;
use App\Modules\Performance\Filament\Resources\ReviewTemplates\Pages\ListReviewTemplates;
use App\Modules\Performance\Models\ReviewTemplate;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Review templates with weighted competencies — docs/saas-launch-plan.md §14.6. */
class ReviewTemplateResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ReviewTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Review Templates';

    protected static ?string $modelLabel = 'Review template';

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->unique(ignoreRecord: true)->placeholder('Annual review, Sales review'),
            TextInput::make('description')->maxLength(190),
            Toggle::make('is_default')->label(__('Use for new cycles'))->helperText('One template is the default; opening a cycle puts its competencies on every review.'),
            Toggle::make('is_active')->default(true),
            Repeater::make('competencies')
                ->relationship()
                ->label(__('Competencies'))
                ->orderColumn('sort')
                ->columns(3)
                ->defaultItems(1)
                ->live()
                ->schema([
                    TextInput::make('name')->required()->maxLength(120)->placeholder('Quality of work'),
                    TextInput::make('weight')->label(__('Weight (%)'))->numeric()->minValue(0)->maxValue(100)->required()->default(0),
                    TextInput::make('description')->maxLength(190)->placeholder('What good looks like here'),
                ])
                ->columnSpanFull(),
            Placeholder::make('weights')
                ->label(__('Weights'))
                ->content(function (Get $get): string {
                    $total = collect((array) $get('competencies'))->sum(fn ($row): float => (float) ($row['weight'] ?? 0));

                    return abs($total - 100) < 0.01
                        ? 'They add up to 100.'
                        : sprintf('They add up to %s. A template is used only once they add up to 100.', rtrim(rtrim(number_format($total, 2), '0'), '.'));
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('competencies_count')->counts('competencies')->label(__('Competencies')),
                TextColumn::make('weight')->label(__('Weights'))->state(fn (ReviewTemplate $t): string => $t->weightProblem() ?? '100')
                    ->color(fn (ReviewTemplate $t): string => $t->weightProblem() === null ? 'success' : 'danger'),
                IconColumn::make('is_default')->boolean()->label(__('Default')),
                IconColumn::make('is_active')->boolean()->label(__('Active')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make(), ReplicateRecordAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewTemplates::route('/'),
            'create' => CreateReviewTemplate::route('/create'),
            'edit' => EditReviewTemplate::route('/{record}/edit'),
        ];
    }
}
