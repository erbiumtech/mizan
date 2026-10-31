<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Recruitment\Filament\Resources\Applications\Actions\HireAction;
use App\Modules\Recruitment\Filament\Resources\Applications\Actions\MakeOfferAction;
use App\Modules\Recruitment\Filament\Resources\Applications\Actions\OfferLetterAction;
use App\Modules\Recruitment\Filament\Resources\Applications\Actions\RejectAction;
use App\Modules\Recruitment\Filament\Resources\Applications\Actions\ScheduleInterviewAction;
use App\Modules\Recruitment\Filament\Resources\Applications\Pages\CreateApplication;
use App\Modules\Recruitment\Filament\Resources\Applications\Pages\EditApplication;
use App\Modules\Recruitment\Filament\Resources\Applications\Pages\ListApplications;
use App\Modules\Recruitment\Filament\Resources\Applications\Pages\ViewApplication;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The pipeline: who is where, and the offer that ends it.
 *
 * Rejecting requires a reason and hiring is its own permission — the two ends of the
 * pipeline are the two decisions with consequences outside it.
 */
class ApplicationResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Application::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Hiring';

    protected static ?int $navigationSort = 12;

    /** The query behind the badge. Batched with every other one on the page — see NavigationBadge. */
    public static function badgeQuery(): ?Builder
    {
        return static::getEloquentQuery()->open();
    }

    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class);
    }

    /**
     * The applicant and the vacancy come with the row.
     *
     * Both are named on every line of the list and in the heading of the candidate page, and both
     * were being fetched per row. It survived only because the fixtures were short: one row never
     * arms the lazy-loading guard.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['applicant', 'vacancy']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vacancy_id')
                ->label(__('Vacancy'))
                ->options(fn (): array => Vacancy::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->required(),

            Select::make('applicant_id')
                ->label(__('Applicant'))
                ->options(fn (): array => Applicant::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->helperText('One application per person per vacancy. Applying to a different vacancy is a second row — that is the memory.'),

            DatePicker::make('applied_on')->native(false)->default(now())->required(),

            Select::make('stage')
                ->options([
                    Application::STAGE_APPLIED => 'Applied',
                    Application::STAGE_SCREENING => 'Screening',
                    Application::STAGE_INTERVIEW => 'Interview',
                    Application::STAGE_OFFER => 'Offer',
                ])
                ->default(Application::STAGE_APPLIED)
                ->required()
                ->helperText('Hiring and rejecting are actions on the list, not choices here — each records why and when.'),

            TextInput::make('rating')->numeric()->minValue(1)->maxValue(5)
                ->helperText('A person\'s judgement, 1 to 5. Nothing computes it.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Eager: the offer, hire and letter actions each ask for the offer per row.
            ->modifyQueryUsing(fn ($query) => $query->with(['offer']))
            ->columns([
                TextColumn::make('applicant.name')->label(__('Applicant'))->searchable()->sortable(),
                TextColumn::make('vacancy.title')->label(__('Vacancy'))->searchable()->sortable(),
                TextColumn::make('applied_on')->date('d M Y')->sortable(),

                TextColumn::make('stage')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Application::STAGE_HIRED => 'success',
                        Application::STAGE_OFFER => 'info',
                        Application::STAGE_INTERVIEW => 'warning',
                        Application::STAGE_REJECTED, Application::STAGE_WITHDRAWN => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Application $record): ?string => $record->rejected_reason)
                    ->sortable(),

                TextColumn::make('rating')->alignEnd()->placeholder('—')->toggleable(),

                TextColumn::make('employee.employee_id')
                    ->label(__('Became'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('applied_on', 'desc')
            ->filters([
                SelectFilter::make('stage')->options([
                    Application::STAGE_APPLIED => 'Applied',
                    Application::STAGE_SCREENING => 'Screening',
                    Application::STAGE_INTERVIEW => 'Interview',
                    Application::STAGE_OFFER => 'Offer',
                    Application::STAGE_HIRED => 'Hired',
                    Application::STAGE_REJECTED => 'Rejected',
                ]),

                SelectFilter::make('vacancy_id')
                    ->label(__('Vacancy'))
                    ->options(fn (): array => Vacancy::orderBy('title')->pluck('title', 'id')->all()),
            ])
            // Rows open the candidate rather than the edit form: the page answers the question
            // somebody clicking a name actually has, and Edit is one button away on it.
            ->recordUrl(fn (Application $record): string => ViewApplication::getUrl([$record]))
            ->recordActions(static::pipelineActions());
    }

    /**
     * Moving a candidate along: schedule, offer, letter, hire, reject.
     *
     * One list, used by the applications table and by the candidate page's header. They were written
     * into the table, so the page would have had to copy them — and five buttons that each decide
     * something irreversible are exactly the five that must not drift into two versions.
     *
     * @return array<int, \Filament\Actions\Action>
     */
    public static function pipelineActions(): array
    {
        return [
            ScheduleInterviewAction::make(),
            MakeOfferAction::make(),
            OfferLetterAction::make(),
            HireAction::make(),
            RejectAction::make(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplications::route('/'),
            'create' => CreateApplication::route('/create'),
            'view' => ViewApplication::route('/{record}'),
            'edit' => EditApplication::route('/{record}/edit'),
        ];
    }
}
