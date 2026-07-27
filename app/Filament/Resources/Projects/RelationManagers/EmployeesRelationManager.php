<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\Employee;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The project's team. Assignments are dated stints, so taking someone off a
 * project sets to_date rather than deleting the row — detach is reserved for
 * correcting mistakes and gated on ProjectDelete.
 */
class EmployeesRelationManager extends RelationManager
{
    protected static string $relationship = 'employees';

    protected static ?string $title = 'Team';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('ProjectView') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('employee_id')
            ->columns([
                TextColumn::make('employee_id')
                    ->label('Employee')
                    ->formatStateUsing(fn (Employee $record): string => $record->display_label)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('designation')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('pivot.role')
                    ->label('Project role')
                    ->placeholder('—'),

                TextColumn::make('pivot.allocation_pct')
                    ->label('Allocation')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%'),

                TextColumn::make('pivot.from_date')
                    ->label('From')
                    ->date(),

                TextColumn::make('pivot.to_date')
                    ->label('To')
                    ->date()
                    ->placeholder('open'),

                TextColumn::make('stint_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Employee $record): string => self::isCurrent($record) ? 'current' : 'ended')
                    ->color(fn (string $state): string => $state === 'current' ? 'success' : 'gray'),
            ])
            ->filters([
                Filter::make('current_only')
                    ->label('Current assignments only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $query) {
                        $query->whereNull('project_employee.to_date')
                            ->orWhereDate('project_employee.to_date', '>=', today()->toDateString());
                    })),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Assign employee')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query->where('is_active', true)->with('user'))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->display_label)
                            // Validated inline so the person sees why in the
                            // modal, rather than as a toast after the fact.
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                                    $employee = Employee::find(is_array($value) ? null : $value);

                                    if (! $employee) {
                                        return;
                                    }

                                    $project = $this->getOwnerRecord();

                                    if ($project->hasOpenAssignment($employee)) {
                                        $fail('That employee already has an open assignment on this project. End it before adding a new one.');

                                        return;
                                    }

                                    $from = $get('from_date') ?: today()->toDateString();

                                    $duplicate = $project->employees()->newPivotStatement()
                                        ->where('project_id', $project->getKey())
                                        ->where('employee_id', $employee->getKey())
                                        ->whereDate('from_date', $from)
                                        ->exists();

                                    if ($duplicate) {
                                        $fail('That employee already has an assignment on this project starting on that date.');
                                    }
                                },
                            ]),
                        TextInput::make('role')
                            ->label('Project role')
                            ->maxLength(255)
                            ->placeholder('Backend lead'),
                        TextInput::make('allocation_pct')
                            ->label('Allocation %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01),
                        DatePicker::make('from_date')
                            ->required()
                            ->native(false)
                            ->default(today()),
                        DatePicker::make('to_date')
                            ->native(false)
                            ->afterOrEqual('from_date'),
                    ])
                    // Routed through Project::assign so the "no second open
                    // stint" rule lives in the model, not in this closure. The
                    // catch is a backstop for non-UI callers and races; the
                    // field rules above are what users normally hit.
                    ->using(function (array $data, $record, AttachAction $action, EmployeesRelationManager $livewire): void {
                        $employee = $record instanceof Employee
                            ? $record
                            : Employee::find($data['recordId'] ?? null);

                        if (! $employee) {
                            return;
                        }

                        try {
                            $livewire->getOwnerRecord()->assign($employee, $data);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            $action->halt();
                        }
                    })
                    ->successNotificationTitle('Employee assigned.'),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Edit stint')
                    ->schema([
                        TextInput::make('role')->label('Project role')->maxLength(255),
                        TextInput::make('allocation_pct')
                            ->label('Allocation %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01),
                        DatePicker::make('from_date')->required()->native(false),
                        DatePicker::make('to_date')->native(false)->afterOrEqual('from_date'),
                    ]),

                Action::make('endAssignment')
                    ->label('End assignment')
                    ->icon('heroicon-m-user-minus')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Sets the end date to today. The stint stays on the record, so the project keeps its history.')
                    ->visible(fn (Employee $record): bool => self::isCurrent($record)
                        && (auth()->user()?->can('ProjectUpdate') ?? false))
                    ->action(function (Employee $record): void {
                        $this->getOwnerRecord()->endAssignment($record);

                        Notification::make()->success()->title('Assignment ended.')->send();
                    }),

                // Detach erases the stint outright, so it is for fixing
                // mistakes only — CEO/Administrator.
                DetachAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('ProjectDelete') ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(fn (): bool => auth()->user()?->can('ProjectDelete') ?? false),
                ]),
            ])
            ->defaultSort('project_employee.from_date', 'desc')
            ->emptyStateHeading('No one assigned yet');
    }

    protected static function isCurrent(Employee $record): bool
    {
        $to = $record->pivot?->to_date;

        return $to === null || $to >= today()->toDateString();
    }
}
