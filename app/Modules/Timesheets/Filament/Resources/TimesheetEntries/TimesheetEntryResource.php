<?php

namespace App\Modules\Timesheets\Filament\Resources\TimesheetEntries;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Filament\Resources\TimesheetEntries\Pages\CreateTimesheetEntry;
use App\Modules\Timesheets\Filament\Resources\TimesheetEntries\Pages\EditTimesheetEntry;
use App\Modules\Timesheets\Filament\Resources\TimesheetEntries\Pages\ListTimesheetEntries;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Services\TimesheetService;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use App\Support\LandlordUserColumn;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

class TimesheetEntryResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = TimesheetEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $modelLabel = 'Timesheet';

    protected static ?int $navigationSort = 50;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                    ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                    $search,
                    EmployeeOptions::accessibleScope(),
                ))
                ->preload()
                ->required()
                ->default(fn (): ?int => Employee::where('user_id', auth()->id())->value('id')),

            Select::make('project_id')
                ->label('Project')
                ->options(fn (): array => Project::orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(),

            DatePicker::make('date')->native(false)->required()->default(now()),

            TextInput::make('minutes')
                ->label('Minutes')
                ->numeric()
                ->minValue(1)
                ->maxValue(1440)
                ->required()
                ->helperText('Minutes, not hours — 90 is an hour and a half. A rate multiplied by rounded hours drifts across a month of entries.'),

            Toggle::make('is_billable')
                ->label('Billable')
                ->default(true)
                ->helperText('Non-billable time is still worth recording: it is what utilisation is measured against. It never reaches an invoice.'),

            TextInput::make('task')->maxLength(255)->placeholder('Migration'),

            Textarea::make('description')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')->date('d M Y')->sortable(),

                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('project.name')->label('Project')->searchable()->sortable(),

                TextColumn::make('task')->placeholder('—')->toggleable(),

                TextColumn::make('minutes')
                    ->label('Time')
                    ->formatStateUsing(fn ($state): string => intdiv((int) $state, 60).'h '
                        .str_pad((string) ((int) $state % 60), 2, '0', STR_PAD_LEFT).'m')
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('is_billable')->label('Billable')->boolean()->sortable(),

                TextColumn::make('approved_at')
                    ->label('Approved')
                    ->date('d M Y')
                    ->placeholder('—')
                    // The three states are worth distinguishing at a glance: unapproved,
                    // approved, and billed-and-frozen.
                    ->description(fn (TimesheetEntry $record): ?string => $record->isLocked()
                        ? 'billed — no longer editable'
                        : null)
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('project_id')
                    ->label('Project')
                    ->options(fn (): array => Project::orderBy('name')->pluck('name', 'id')->all()),

                TernaryFilter::make('is_billable')->label('Billable'),

                Filter::make('unapproved')
                    ->label('Awaiting approval')
                    ->query(fn (Builder $query): Builder => $query->whereNull('approved_at')),

                Filter::make('this_month')
                    ->label('This month')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->inMonth(now()->year, now()->month)),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (TimesheetEntry $record): bool => ! $record->isApproved()
                        && (auth()->user()?->can('approve', $record) ?? false))
                    ->action(function (TimesheetEntry $record): void {
                        try {
                            app(TimesheetService::class)->approve($record, auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Approved — this time can now be billed.')->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTimesheetEntries::route('/'),
            'create' => CreateTimesheetEntry::route('/create'),
            'edit' => EditTimesheetEntry::route('/{record}/edit'),
        ];
    }
}
