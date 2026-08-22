<?php

namespace App\Modules\Recruitment\Filament\Resources\Vacancies;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Recruitment\Filament\Resources\Vacancies\Pages\CreateVacancy;
use App\Modules\Recruitment\Filament\Resources\Vacancies\Pages\EditVacancy;
use App\Modules\Recruitment\Filament\Resources\Vacancies\Pages\ListVacancies;
use App\Modules\Recruitment\Models\Vacancy;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class VacancyResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Vacancy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Hiring';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('title')->required()->maxLength(255),

            Select::make('status')
                ->options([
                    Vacancy::STATUS_DRAFT => 'Draft',
                    Vacancy::STATUS_OPEN => 'Open',
                    Vacancy::STATUS_ON_HOLD => 'On hold',
                    Vacancy::STATUS_FILLED => 'Filled',
                    Vacancy::STATUS_CLOSED => 'Closed',
                ])
                ->default(Vacancy::STATUS_DRAFT)
                ->required(),

            TextInput::make('openings')->numeric()->minValue(1)->default(1)
                ->helperText('The vacancy closes itself when the last opening is filled.'),

            TextInput::make('department')->maxLength(255),
            TextInput::make('designation')->maxLength(255),
            TextInput::make('employment_type')->maxLength(255)->placeholder('Permanent'),

            // Guarded: a company hiring its first person has no employee to name.
            Select::make('hiring_manager_employee_id')
                ->label('Hiring manager')
                ->options(fn (): array => Employee::query()
                    ->where('is_active', true)
                    ->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                    ->all())
                ->searchable()
                ->visible(fn (): bool => modules()->enabled('employees')),

            TextInput::make('salary_min')->numeric(),
            TextInput::make('salary_max')->numeric(),

            DatePicker::make('opened_on')->native(false)->default(now()),

            Textarea::make('description')->rows(4)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('department')->placeholder('—')->toggleable(),

                TextColumn::make('applications_count')
                    ->label('Applications')
                    ->counts('applications')
                    ->alignEnd(),

                TextColumn::make('openings')
                    ->alignEnd()
                    ->description(fn (Vacancy $record): ?string => $record->remainingOpenings() < $record->openings
                        ? $record->remainingOpenings().' still open'
                        : null),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Vacancy::STATUS_OPEN => 'success',
                        Vacancy::STATUS_ON_HOLD => 'warning',
                        Vacancy::STATUS_FILLED => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    Vacancy::STATUS_OPEN => 'Open',
                    Vacancy::STATUS_ON_HOLD => 'On hold',
                    Vacancy::STATUS_FILLED => 'Filled',
                    Vacancy::STATUS_CLOSED => 'Closed',
                ]),
            ])
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVacancies::route('/'),
            'create' => CreateVacancy::route('/create'),
            'edit' => EditVacancy::route('/{record}/edit'),
        ];
    }
}
