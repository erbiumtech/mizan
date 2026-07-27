<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Models\Project;
use App\Models\ProjectEnvironment;
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
                            ->label('Code')
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
                            ->label('Primary manager')
                            ->relationship('manager', 'employee_id')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        Select::make('secondary_employee_id')
                            ->label('Secondary manager')
                            ->relationship('secondaryManager', 'employee_id')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->different('manager_employee_id')
                            ->helperText('Stand-in for the primary manager. Must be a different person.'),
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
                                    ->label('Health checks')
                                    ->default(true)
                                    ->helperText("Turn off for URLs the server can't reach, e.g. localhost."),

                                Toggle::make('alerts_enabled')
                                    ->label('Alert on outage')
                                    ->default(true)
                                    ->helperText('Emails the primary and secondary manager once an outage is confirmed.'),

                                TextInput::make('check_interval_min')
                                    ->label('Check every (minutes)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(1440)
                                    ->placeholder(config('projects.health.default_interval')),

                                TextInput::make('expected_content')
                                    ->label('Body must contain')
                                    ->maxLength(255)
                                    ->helperText('Optional. Catches a 200 that renders an error page.'),

                                TextInput::make('expected_status')
                                    ->label('Expected HTTP status')
                                    ->numeric()
                                    ->minValue(100)
                                    ->maxValue(599)
                                    ->placeholder('2xx / 3xx / 401 / 403'),

                                Toggle::make('is_public')
                                    ->label('Show on public status page')
                                    ->default(false)
                                    ->helperText('Only the status and uptime are published — never the URL or credentials.'),
                            ]),
                    ]),

                ...CustomFieldsSchema::form(Project::class),
            ]);
    }
}
