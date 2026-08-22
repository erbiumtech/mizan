<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Recruitment\Filament\Resources\Applications\Pages\CreateApplication;
use App\Modules\Recruitment\Filament\Resources\Applications\Pages\EditApplication;
use App\Modules\Recruitment\Filament\Resources\Applications\Pages\ListApplications;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Recruitment\Services\HireService;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;
use RuntimeException;
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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Hiring';

    protected static ?int $navigationSort = 12;

    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class, fn (): int => static::getEloquentQuery()->open()->count());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vacancy_id')
                ->label('Vacancy')
                ->options(fn (): array => Vacancy::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->required(),

            Select::make('applicant_id')
                ->label('Applicant')
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
            ->columns([
                TextColumn::make('applicant.name')->label('Applicant')->searchable()->sortable(),
                TextColumn::make('vacancy.title')->label('Vacancy')->searchable()->sortable(),
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
                    ->label('Became')
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
                    ->label('Vacancy')
                    ->options(fn (): array => Vacancy::orderBy('title')->pluck('title', 'id')->all()),
            ])
            ->recordActions([
                Action::make('makeOffer')
                    ->label('Make an offer')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->visible(fn (Application $record): bool => $record->isOpen()
                        && ! $record->offer
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->schema([
                        TextInput::make('salary')->numeric()->required()->label('Basic salary'),
                        DatePicker::make('joining_date')->native(false)->required(),
                    ])
                    ->action(function (Application $record, array $data): void {
                        Offer::create([
                            'application_id' => $record->getKey(),
                            'salary' => $data['salary'],
                            'joining_date' => $data['joining_date'],
                            'status' => Offer::STATUS_ISSUED,
                            'issued_at' => now(),
                        ]);

                        $record->update(['stage' => Application::STAGE_OFFER]);

                        Notification::make()->success()->title('Offer recorded.')->send();
                    }),

                /**
                 * Hire: the conversion.
                 *
                 * Creates the employee, the optional login and the salary package in ONE
                 * transaction — a company left with an employee who has no package, or a
                 * package with no employee, is worse off than one whose button errored.
                 *
                 * Absent, not disabled, without the Employees module: a company hiring
                 * its very first person has nothing for the offer to become yet.
                 */
                Action::make('hire')
                    ->label('Accept and hire')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->visible(fn (Application $record): bool => $record->offer
                        && (auth()->user()?->can('hire', $record->offer) ?? false))
                    ->schema([
                        TextInput::make('employee_code')
                            ->label('Employee code')
                            ->maxLength(50)
                            ->helperText('Leave blank for the next in sequence. Companies have their own conventions.'),

                        Checkbox::make('with_login')
                            ->label('Create a login as well')
                            ->helperText('Needs an email address. A factory floor usually does not get accounts — employees without a login are supported.'),
                    ])
                    ->modalDescription('This creates the employee, their salary package from the offer, and optionally a login — all or nothing.')
                    ->action(function (Application $record, array $data): void {
                        try {
                            $employee = app(HireService::class)->hire(
                                $record->offer,
                                (bool) ($data['with_login'] ?? false),
                                $data['employee_code'] ?: null,
                            );
                        } catch (InvalidArgumentException|RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title("Hired as {$employee->employee_id}.")
                            ->body('The application keeps the trail from vacancy to payroll.')
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Application $record): bool => $record->isOpen()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->rows(2)
                            ->helperText('Kept on the record. It is also what starts the two-year retention clock on the applicant.'),
                    ])
                    ->action(function (Application $record, array $data): void {
                        $record->update([
                            'stage' => Application::STAGE_REJECTED,
                            'rejected_reason' => $data['reason'],
                            'rejected_at' => now(),
                        ]);

                        Notification::make()->success()->title('Rejected, with the reason recorded.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplications::route('/'),
            'create' => CreateApplication::route('/create'),
            'edit' => EditApplication::route('/{record}/edit'),
        ];
    }
}
