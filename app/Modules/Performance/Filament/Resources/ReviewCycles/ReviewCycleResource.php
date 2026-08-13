<?php

namespace App\Modules\Performance\Filament\Resources\ReviewCycles;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Performance\Filament\Resources\ReviewCycles\Pages\CreateReviewCycle;
use App\Modules\Performance\Filament\Resources\ReviewCycles\Pages\EditReviewCycle;
use App\Modules\Performance\Filament\Resources\ReviewCycles\Pages\ListReviewCycles;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Services\ReviewCycleService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;
use UnitEnum;

class ReviewCycleResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ReviewCycle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->placeholder('2026 Annual'),

            Select::make('status')
                ->options([
                    ReviewCycle::STATUS_DRAFT => 'Draft',
                    ReviewCycle::STATUS_OPEN => 'Open',
                    ReviewCycle::STATUS_CALIBRATING => 'Calibrating',
                    ReviewCycle::STATUS_CLOSED => 'Closed',
                ])
                ->default(ReviewCycle::STATUS_DRAFT)
                ->required(),

            DatePicker::make('period_start')->native(false)->required()
                ->helperText('The monthly progress reports filed inside this window are read as evidence.'),
            DatePicker::make('period_end')->native(false)->required()->afterOrEqual('period_start'),

            DatePicker::make('self_review_due_on')->native(false),
            DatePicker::make('manager_review_due_on')->native(false),

            Textarea::make('calibration_notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('period_start')
                    ->label('Period')
                    ->date('d M Y')
                    ->description(fn (ReviewCycle $record): string => 'to '.$record->period_end->format('d M Y')),

                TextColumn::make('reviews_count')->label('Reviews')->counts('reviews')->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ReviewCycle::STATUS_OPEN => 'success',
                        ReviewCycle::STATUS_CALIBRATING => 'warning',
                        ReviewCycle::STATUS_CLOSED => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([
                Action::make('open')
                    ->label('Raise reviews')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Raises a review for every active employee, with their current manager as reviewer. Running it again adds only what is missing.')
                    ->visible(fn (ReviewCycle $record): bool => ! $record->isClosed()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (ReviewCycle $record): void {
                        try {
                            $raised = app(ReviewCycleService::class)->open($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title($raised === 0 ? 'Every employee already has a review.' : "Raised {$raised} review(s).")
                            ->send();
                    }),

                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewCycles::route('/'),
            'create' => CreateReviewCycle::route('/create'),
            'edit' => EditReviewCycle::route('/{record}/edit'),
        ];
    }
}
