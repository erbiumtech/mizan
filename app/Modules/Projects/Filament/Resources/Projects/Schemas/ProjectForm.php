<?php

namespace App\Modules\Projects\Filament\Resources\Projects\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Support\EmployeeOptions;
use App\Support\TenantDb;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label(__('Code'))
                            ->required()
                            ->maxLength(50)
                            ->unique(table: Project::class, column: 'code', ignoreRecord: true)
                            ->helperText('e.g. PRJ-ERP-01'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Select::make('status')
                            ->options(Project::STATUSES)
                            ->default(Project::STATUS_PLANNED)
                            ->required()
                            ->native(false),

                        Textarea::make('description')
                            ->nullable()
                            ->columnSpanFull(),

                        DatePicker::make('start_date')->native(false),

                        DatePicker::make('end_date')
                            ->native(false)
                            ->afterOrEqual('start_date'),
                    ]),

                Section::make('Management')
                    ->columns(2)
                    ->description('Two people are recorded as running the project; the secondary is the one to go to when the primary is unavailable.')
                    ->schema([
                        Select::make('manager_employee_id')
                            ->label(__('Primary manager'))
                            ->relationship('manager', 'employee_id')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                            ->searchable()
                            // The option label carries the person's name, so the
                            // search has to match it; Filament alone would only
                            // match the employee_id title attribute.
                            ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search($search))
                            ->preload()
                            ->nullable(),

                        Select::make('secondary_employee_id')
                            ->label(__('Secondary manager'))
                            ->relationship('secondaryManager', 'employee_id')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search($search))
                            ->preload()
                            ->nullable()
                            ->different('manager_employee_id')
                            ->helperText('Stand-in for the primary manager. Must be a different person.'),

                        // Who the project is for and what an hour on it bills at — the two columns billing and
                        // the timesheet reports read, which no screen could set. Contacts by name through
                        // TenantDb, as the timesheet reports read them: Projects does not import Invoicing.
                        Select::make('contact_id')
                            ->label(__('Client'))
                            ->options(fn (?Project $record): array => TenantDb::table('contacts')
                                ->where(fn ($query) => $query->whereIn('kind', ['customer', 'both'])->where('is_active', true))
                                ->when($record?->contact_id, fn ($query, $id) => $query->orWhere('id', $id))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->nullable()
                            ->placeholder('Internal')
                            ->visible(fn (): bool => modules()->enabled('invoicing'))
                            ->helperText('Leave empty for internal work. A client is what bills this project\'s hours.'),

                        TextInput::make('hourly_rate')
                            ->label(__('Hourly rate'))
                            ->numeric()
                            ->minValue(0)
                            ->nullable()
                            ->visible(fn (): bool => modules()->enabled('timesheets'))
                            ->helperText('What an hour on this project bills at. Empty falls back to the employee\'s rate, then the company default.'),
                    ]),

                Section::make('Environments')
                    ->description('Deployment targets with their shared login details. Passwords are stored and shown in plain text.')
                    ->schema([
                        Repeater::make('environments')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(2)
                            ->collapsed()
                            ->itemLabel(fn (array $state): string => ProjectEnvironment::KINDS[$state['kind'] ?? ''] ?? 'Environment')
                            ->defaultItems(3)
                            ->default([
                                ['kind' => ProjectEnvironment::KIND_PROD, 'is_monitored' => true, 'alerts_enabled' => true, 'is_public' => false],
                                ['kind' => ProjectEnvironment::KIND_QUAL, 'is_monitored' => true, 'alerts_enabled' => true, 'is_public' => false],
                                ['kind' => ProjectEnvironment::KIND_DEV, 'is_monitored' => true, 'alerts_enabled' => false, 'is_public' => false],
                            ])
                            ->schema([
                                Select::make('kind')
                                    ->options(ProjectEnvironment::KINDS)
                                    ->required()
                                    ->distinct()
                                    ->native(false),

                                TextInput::make('url')
                                    ->url()
                                    ->maxLength(255)
                                    ->placeholder('https://…'),

                                TextInput::make('username')
                                    ->maxLength(255),

                                TextInput::make('password')
                                    ->maxLength(255)
                                    ->helperText('Stored and displayed in plain text.'),

                                Textarea::make('notes')
                                    ->rows(2)
                                    ->columnSpanFull()
                                    ->placeholder('VPN requirement, bastion host, seeded test users…'),

                                Toggle::make('is_monitored')
                                    ->label(__('Health checks'))
                                    ->default(true)
                                    ->helperText("Turn off for URLs the server can't reach, e.g. localhost."),

                                Toggle::make('alerts_enabled')
                                    ->label(__('Alert on outage'))
                                    ->default(true)
                                    ->helperText('Emails the primary and secondary manager once an outage is confirmed.'),

                                TextInput::make('check_interval_min')
                                    ->label(__('Check every (minutes)'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(1440)
                                    ->placeholder(config('projects.health.default_interval')),

                                TextInput::make('expected_content')
                                    ->label(__('Body must contain'))
                                    ->maxLength(255)
                                    ->helperText('Optional. Catches a 200 that renders an error page.'),

                                TextInput::make('expected_status')
                                    ->label(__('Expected HTTP status'))
                                    ->numeric()
                                    ->minValue(100)
                                    ->maxValue(599)
                                    ->placeholder('2xx / 3xx / 401 / 403'),

                                Toggle::make('is_public')
                                    ->label(__('Show on public status page'))
                                    ->default(false)
                                    ->helperText('Only the status and uptime are published — never the URL or credentials.'),
                            ]),
                    ]),

                /*
                 * The project's own say on three work-module defaults — docs/project-management-plan.md
                 * §8.4 and §8.8. Plain columns on this table, offered only when the module is enabled,
                 * the same guarded shape as the client picker above: Projects names no class of the
                 * work module's and the columns simply stay null without it.
                 */
                Section::make('Work')
                    ->columns(3)
                    ->description('Overrides for this project. Empty means the company default under Settings → Work.')
                    ->visible(fn (): bool => modules()->enabled('work'))
                    ->schema([
                        Select::make('work_merge_moves_to_done')
                            ->label(__('A merged pull request'))
                            ->options([1 => 'Resolves the items it names', 0 => 'Is only recorded'])
                            ->placeholder('Company default')
                            ->native(false),

                        Select::make('work_time_source')
                            ->label(__('Time is logged'))
                            ->options(['here' => 'Here', 'clockify' => 'In Clockify', 'either' => 'Either'])
                            ->placeholder('Company default')
                            ->native(false),

                        Select::make('work_task_on_progress')
                            ->label(__('Clockify task is created'))
                            ->options([0 => 'On the first timer', 1 => 'When the item starts'])
                            ->placeholder('On the first timer')
                            ->native(false)
                            ->helperText('A task for every item ever started fills a workspace with tasks nobody timed.'),
                    ]),

                ...CustomFieldsSchema::form(Project::class),
            ]);
    }
}
