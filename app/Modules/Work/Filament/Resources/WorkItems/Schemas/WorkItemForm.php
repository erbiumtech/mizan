<?php

namespace App\Modules\Work\Filament\Resources\WorkItems\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkRelease;
use App\Modules\Work\Models\WorkStatus;
use App\Support\EmployeeAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * What a work item is, in four sections.
 *
 * The order is the order somebody fills it in: what it is, where it sits, who has it, and how big.
 * Everything below the first section is optional — a bug filed in thirty seconds is a title and a
 * project, and a tool that asks for an estimate before it will take a bug report is a tool where
 * bugs get reported in chat.
 */
class WorkItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What it is')
                ->columns(2)
                ->schema([
                    Select::make('project_id')
                        ->label('Project')
                        ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required()
                        // The key is minted from the project, so changing it after the fact would
                        // change the item's name. WorkItems::key() refuses that too.
                        ->disabledOn('edit')
                        ->live(),

                    Select::make('type')
                        ->label('Type')
                        ->options(fn (?WorkItem $record): array => options('work.type', $record?->type))
                        ->default('Task')
                        ->required(),

                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->placeholder('Export fails on Safari when the month has no payslips'),

                    MarkdownEditor::make('description')
                        ->label('Description')
                        ->columnSpanFull()
                        // Markdown rather than a rich editor: a description is pasted into pull
                        // requests and chat, and Markdown is what survives the trip.
                        ->helperText('Markdown. Pasted into a pull request it reads the same there.'),
                ]),

            Section::make('Where it sits')
                ->columns(2)
                ->schema([
                    Select::make('work_status_id')
                        ->label('Status')
                        ->options(fn (): array => WorkStatus::query()
                            ->orderBy('sort')
                            ->get()
                            ->mapWithKeys(fn (WorkStatus $s): array => [$s->getKey() => $s->name])
                            ->all())
                        ->required()
                        ->helperText('Moving it here goes through the same rules the board does.'),

                    Select::make('priority')
                        ->label('Priority')
                        ->options(fn (?WorkItem $record): array => options('work.priority', $record?->priority))
                        ->default('Medium'),

                    Select::make('parent_id')
                        ->label('Parent')
                        ->options(fn (?WorkItem $record): array => WorkItem::query()
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                            ->notOfType(WorkItem::TYPE_SUBTASK)
                            ->orderByDesc('id')
                            ->limit(100)
                            ->get()
                            ->mapWithKeys(fn (WorkItem $i): array => [$i->getKey() => $i->display_label])
                            ->all())
                        ->searchable()
                        ->placeholder('None')
                        ->helperText('An epic over a story, or a story over a subtask. One level each way.'),

                    // The fix version — §6.4. Only releases of the item's own project that have not
                    // gone out yet: promising an item into something already shipped is a changelog
                    // that rewrites itself.
                    Select::make('release_id')
                        ->label('Release')
                        ->options(fn (Get $get, ?WorkItem $record): array => WorkRelease::query()
                            ->when($get('project_id'), fn ($q, $project) => $q->where('project_id', $project))
                            ->where(fn ($q) => $q->whereNull('released_on')
                                ->when($record?->release_id, fn ($qq, $id) => $qq->orWhere('id', $id)))
                            ->orderBy('planned_on')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->placeholder('Not promised to one')
                        ->helperText('The version it ships in. Releasing stamps a date and moves nothing.'),

                    Select::make('resolution')
                        ->label('Resolution')
                        ->options(fn (?WorkItem $record): array => options('work.resolution', $record?->resolution))
                        ->placeholder('Not resolved')
                        ->helperText('How it ended. Some statuses ask for this before they will take it.'),
                ]),

            Section::make('Who has it')
                ->columns(2)
                ->schema([
                    Select::make('assignee_employee_id')
                        ->label('Assignee')
                        ->options(fn (): array => Employee::query()
                            ->where('is_active', true)
                            ->with('user')
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])
                            ->all())
                        ->searchable()
                        ->placeholder('Nobody yet')
                        // One assignee. Not many: a card with three faces on it is a card nobody owns.
                        ->default(fn (): ?int => app(EmployeeAccess::class)->ownEmployeeId(auth()->user())),

                    DatePicker::make('due_on')
                        ->label('Due')
                        ->native(false),
                ]),

            Section::make('How big')
                ->columns(2)
                ->description('Both are optional, and a company uses one or the other — see Settings.')
                ->schema([
                    TextInput::make('estimate_points')
                        ->label('Points')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.5),

                    TextInput::make('estimate_minutes')
                        ->label('Estimate (minutes)')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('90 is an hour and a half. Time logged against it is a timesheet entry.'),

                    ...CustomFieldsSchema::form(WorkItem::class),
                ]),
        ]);
    }
}
