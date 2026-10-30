<?php

namespace App\Modules\Performance\Filament\Resources\RecurringTasks;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Core\Models\WorkLocation;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Rules\RuleForm;
use App\Modules\Performance\Filament\Resources\RecurringTasks\Pages\CreateRecurringTask;
use App\Modules\Performance\Filament\Resources\RecurringTasks\Pages\EditRecurringTask;
use App\Modules\Performance\Filament\Resources\RecurringTasks\Pages\ListRecurringTasks;
use App\Modules\Performance\Models\RecurringTask;
use App\Support\CompanyCurrency;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Recurring tasks — docs/saas-launch-plan.md §8.2. Set here by a manager;
 * `tasks:roll` turns each into one occurrence per person per due date.
 */
class RecurringTaskResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = RecurringTask::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(160),
            Select::make('kind')
                ->options([
                    RecurringTask::KIND_TASK => 'A task — done when the person marks it done',
                    RecurringTask::KIND_DAILY_REPORT => 'The daily report — done when the person files one for the day',
                ])
                ->default(RecurringTask::KIND_TASK)
                ->required()
                ->helperText('One daily-report task per audience is enough: filing a report completes it, and a missed one is a missed report.'),
            Textarea::make('description')->rows(3)->columnSpanFull(),
            Section::make('Who')
                ->schema([
                    Select::make('assignee_kind')
                        ->label('Assigned to')
                        ->options([
                            RecurringTask::ASSIGNEE_ALL => 'Everyone',
                            RecurringTask::ASSIGNEE_EMPLOYEE => 'One employee',
                            RecurringTask::ASSIGNEE_DEPARTMENT => 'A department',
                            RecurringTask::ASSIGNEE_LOCATION => 'A location',
                            RecurringTask::ASSIGNEE_RULE => 'By rule',
                        ])
                        ->default(RecurringTask::ASSIGNEE_ALL)
                        ->required()
                        ->live(),
                    Select::make('assignee_ref')
                        ->label('Employee')
                        ->options(fn (): array => Employee::query()->where('is_active', true)->orderBy('employee_id')->get()
                            ->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => $get('assignee_kind') === RecurringTask::ASSIGNEE_EMPLOYEE)
                        ->required(fn (Get $get): bool => $get('assignee_kind') === RecurringTask::ASSIGNEE_EMPLOYEE),
                    Select::make('assignee_ref')
                        ->label('Department')
                        ->options(fn (?RecurringTask $record): array => options('employees.department', $record?->assignee_ref))
                        ->visible(fn (Get $get): bool => $get('assignee_kind') === RecurringTask::ASSIGNEE_DEPARTMENT)
                        ->required(fn (Get $get): bool => $get('assignee_kind') === RecurringTask::ASSIGNEE_DEPARTMENT),
                    Select::make('assignee_ref')
                        ->label('Location')
                        ->options(fn (): array => WorkLocation::options())
                        ->visible(fn (Get $get): bool => $get('assignee_kind') === RecurringTask::ASSIGNEE_LOCATION)
                        ->required(fn (Get $get): bool => $get('assignee_kind') === RecurringTask::ASSIGNEE_LOCATION),
                    // Who, by rule — docs/saas-launch-plan.md §8.9. Resolved on every roll, so a new hire the rule matches is in tomorrow.
                    Group::make(RuleForm::schema('assignee_rule'))
                        ->visible(fn (Get $get): bool => $get('assignee_kind') === RecurringTask::ASSIGNEE_RULE)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('When')
                ->schema([
                    Select::make('cadence')
                        ->options([
                            RecurringTask::CADENCE_DAILY => 'Every day',
                            RecurringTask::CADENCE_WEEKLY => 'Every week',
                            RecurringTask::CADENCE_MONTHLY => 'Every month',
                        ])
                        ->default(RecurringTask::CADENCE_DAILY)
                        ->required()
                        ->live(),
                    Select::make('due_weekday')
                        ->label('On')
                        ->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'])
                        ->visible(fn (Get $get): bool => $get('cadence') === RecurringTask::CADENCE_WEEKLY)
                        ->required(fn (Get $get): bool => $get('cadence') === RecurringTask::CADENCE_WEEKLY),
                    TextInput::make('due_day_of_month')
                        ->label('On day')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(31)
                        ->visible(fn (Get $get): bool => $get('cadence') === RecurringTask::CADENCE_MONTHLY)
                        ->required(fn (Get $get): bool => $get('cadence') === RecurringTask::CADENCE_MONTHLY)
                        ->helperText('31 means the last day of a shorter month.'),
                    TimePicker::make('due_time')->label('By')->seconds(false)->default('17:00')->required(),
                    TextInput::make('grace_hours')->label('Grace (hours)')->numeric()->minValue(0)->maxValue(72)->default(0),
                    Toggle::make('working_days_only')
                        ->default(true)
                        ->helperText('Skips weekends by the work pattern and holidays by the calendar — the employee\'s own location\'s.'),
                ])
                ->columns(3),
            Section::make('What a miss costs')
                ->description('Points go on the merit ledger the moment the grace runs out; a manager can excuse a miss, which reverses them. The amount is summed by tasks:settle for payroll to enter — nothing docks pay by itself.')
                ->schema([
                    TextInput::make('penalty_points')->numeric()->minValue(0)->maxValue(100)->default(0),
                    TextInput::make('penalty_amount')->label('Amount (PKR)')->numeric()->minValue(0)->nullable(),
                    Toggle::make('is_active')->default(true),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('assignee')->label('Assigned to')->state(fn (RecurringTask $record): string => $record->assigneeLabel()),
                TextColumn::make('cadence')->badge(),
                TextColumn::make('due_time')->label('By')->time('H:i'),
                TextColumn::make('penalty_points')->label('Points')->alignEnd(),
                TextColumn::make('penalty_amount')->label('Amount')->money(CompanyCurrency::code())->placeholder('—')->alignEnd(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('title')
            ->filters([TernaryFilter::make('is_active')->label('Active')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringTasks::route('/'),
            'create' => CreateRecurringTask::route('/create'),
            'edit' => EditRecurringTask::route('/{record}/edit'),
        ];
    }
}
