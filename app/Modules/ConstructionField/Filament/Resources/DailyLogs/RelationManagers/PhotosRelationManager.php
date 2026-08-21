<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers;

use App\Modules\Construction\Models\Location;
use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DailyLogPhoto;
use App\Modules\ConstructionField\Services\DailyLogService;
use App\Modules\ConstructionField\Services\SitePhotoPromotion;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The day's photographs — §16.1.
 *
 * **Their own table, not the ISO 19650 register**, and §16.1 gives the reason in a sentence: "a site photo has no
 * revision, no suitability code and no approval, and forcing thousands of them into the ISO 19650 register creates junk
 * containers and buries the drawings the register exists for". Fifty a day for two years is thirty thousand containers
 * in a register whose entire purpose is that somebody can find the current issue of a drawing.
 *
 * And then the exception §16.1 names: **promote to register**, for the handful that are as-built evidence. It is gated
 * on the register's own create permission rather than the diary's, because whoever may write a diary is not
 * automatically whoever may put a container in the register — and that gate is the only thing keeping the register out
 * of the state the separate table exists to avoid.
 *
 * **Concealed work is the subject that carries money**, which is why the table filters for it: a photograph of
 * reinforcement before the pour is the only evidence the steel was ever there.
 */
class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    protected static ?string $title = 'Photos';

    private function log(): DailyLog
    {
        /** @var DailyLog $log */
        $log = $this->getOwnerRecord();

        return $log;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                FileUpload::make('file_path')
                    ->label('Photograph')
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('construction/site-photos')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                    ->maxSize(12288)
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Taken on a phone, uploaded from one. The register is for drawings; this is where the day\'s photographs live.'),

                TextInput::make('caption')
                    ->required()
                    ->maxLength(255)
                    ->helperText('An uncaptioned image is unfindable by month four, which is the first month anybody looks.'),

                Select::make('subject')
                    ->options(DailyLogPhoto::SUBJECTS)
                    ->default(DailyLogPhoto::SUBJECT_PROGRESS)
                    ->required()
                    ->helperText('Concealed work is the one that carries money — before the pour, before the screed, before the backfill.'),

                Select::make('location_id')
                    ->label('Where')
                    ->options(fn (): array => $this->locations())
                    ->searchable()
                    ->helperText('From the job\'s location tree, so "every open item in this room" stays answerable.'),

                Select::make('delivery_id')
                    ->label('Of which delivery')
                    ->options(fn (): array => $this->log()->deliveries()
                        ->pluck('description', 'id')
                        ->all())
                    ->searchable()
                    ->helperText('A photograph of the load or the docket.'),

                Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The job's location tree, top-down.
     *
     * `construction_locations` lives in the spine, which this module requires, so this is a real query on a real model —
     * unlike the trade and the machine on the other tabs, which are read as integers because their module may be absent.
     *
     * @return array<int, string>
     */
    private function locations(): array
    {
        return Location::query()
            ->where('job_id', $this->log()->job_id)
            ->orderByRaw('LENGTH(path)')
            ->orderBy('sort_order')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Location $location): array => [$location->getKey() => $location->fullName()])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('caption')
            ->defaultSort('id')
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->columns([
                ImageColumn::make('file_path')
                    ->label('')
                    ->disk('public')
                    ->height(140),

                TextColumn::make('caption')
                    ->weight('medium')
                    ->wrap(),

                TextColumn::make('subject')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DailyLogPhoto::SUBJECTS[$state] ?? $state)
                    ->color(fn (DailyLogPhoto $record): string => $record->isEvidential() ? 'warning' : 'gray'),

                TextColumn::make('location.name')
                    ->label('Where')
                    ->getStateUsing(fn (DailyLogPhoto $record): ?string => $record->location?->fullName())
                    ->placeholder('—'),

                TextColumn::make('promoted_document_id')
                    ->label('In the register')
                    ->getStateUsing(fn (DailyLogPhoto $record): ?string => $record->promotedDocument?->information_container_id)
                    ->placeholder(fn (DailyLogPhoto $record): string => $record->needsPromoting()
                        // Named rather than blank: evidence of covered work that only exists in a diary is findable by
                        // whoever remembers the date.
                        ? 'evidence, not in the register'
                        : '—')
                    ->color(fn (DailyLogPhoto $record): string => $record->needsPromoting() ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('subject')->options(DailyLogPhoto::SUBJECTS),

                Filter::make('needs_promoting')
                    ->label('Evidence not in the register')
                    ->query(fn (Builder $query): Builder => $query->evidential()->whereNull('promoted_document_id')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false)
                    ->using(fn (array $data): Model => app(DailyLogService::class)->addPhoto($this->log(), $data)),
            ])
            ->recordActions([
                /*
                 * **Promote to the register** — §16.1's named exception.
                 *
                 * Gated on `ConstructionDocumentCreate`, not on the diary's own grant. It creates the container at
                 * work-in-progress and never publishes it: publishing is §15's approval gate and a third permission
                 * again, and a promotion that published would be a way around it.
                 */
                Action::make('promote')
                    ->label('Promote to register')
                    ->icon('heroicon-o-arrow-up-on-square')
                    ->visible(fn (DailyLogPhoto $record): bool => ! $record->isPromoted()
                        && (auth()->user()?->can('ConstructionDocumentCreate') ?? false))
                    ->schema(fn (DailyLogPhoto $record): array => [
                        TextInput::make('title')
                            ->label('Container title')
                            ->default($record->caption)
                            ->required()
                            ->maxLength(255),

                        Toggle::make('is_contractual')
                            ->label('Contractual evidence')
                            ->helperText('Mark it where somebody will rely on this photograph in an account or a dispute.')
                            ->default(fn (): bool => $record->isEvidential()),
                    ])
                    ->action(function (DailyLogPhoto $record, array $data): void {
                        $document = app(SitePhotoPromotion::class)->promote($record, $data);

                        Notification::make()
                            ->title("In the register as {$document->information_container_id}")
                            ->body('Work in progress — somebody with publishing rights still has to issue it.')
                            ->success()
                            ->send();
                    }),

                EditAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false),

                DeleteAction::make()
                    // Hidden once promoted, because the register's revision points at this same file. The model refuses
                    // it too — this only saves somebody the error.
                    ->visible(fn (DailyLogPhoto $record): bool => ! $record->isPromoted()
                        && (auth()->user()?->can('update', $this->log()) ?? false)),
            ])
            ->emptyStateHeading('No photographs')
            ->emptyStateDescription('A photograph of reinforcement before the pour is the only evidence the steel was ever there.')
            ->paginationPageOptions([12, 24, 48]);
    }
}
