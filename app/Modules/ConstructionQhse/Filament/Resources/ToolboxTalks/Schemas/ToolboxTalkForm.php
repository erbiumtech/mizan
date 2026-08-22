<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\ConstructionQhse\Models\Incident;
use App\Modules\ConstructionQhse\Models\ToolboxTalk;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Recording a talk.
 *
 * **The topic is required and the time is a datetime.** "Toolbox talk" against a date proves a meeting happened and says
 * nothing about what anybody was told; and a talk at seven in the morning before the shift and one at four in the
 * afternoon are different facts about a site — the second is usually a talk given to a tick-box.
 *
 * **What prompted it** is worth its own field: a talk given after an incident is a *response*, and a register that could
 * not distinguish one from a routine could not show a site learning anything.
 */
class ToolboxTalkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The talk')
                    ->columns(2)
                    ->schema([
                        Select::make('job_id')
                            ->label('Job')
                            ->options(fn (): array => Job::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabled(fn (?ToolboxTalk $record): bool => $record !== null)
                            ->dehydrated(),

                        TextInput::make('topic')
                            ->required()
                            ->maxLength(255)
                            ->helperText('What was actually covered. "Toolbox talk" against a date proves a meeting happened and nothing else.'),

                        DateTimePicker::make('delivered_at')
                            ->label('Given at')
                            ->seconds(false)
                            ->default(now())
                            ->required()
                            ->helperText('The time matters: one before the shift and one at four in the afternoon are different facts about a site.'),

                        TextInput::make('presenter_label')
                            ->label('Given by')
                            ->maxLength(255),

                        Select::make('location_id')
                            ->label('Where')
                            ->options(fn (callable $get): array => static::locations($get('job_id')))
                            ->searchable(),

                        TextInput::make('duration_minutes')->label('Minutes')->numeric(),

                        Textarea::make('content_summary')
                            ->label('What was said')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('What prompted it')
                    ->columns(2)
                    ->description('A talk given in response to something is worth knowing about as such — a register that could not distinguish a response from a routine could not show a site learning anything.')
                    ->schema([
                        TextInput::make('prompted_by')
                            ->maxLength(255)
                            ->helperText('A near miss, a client observation, a high-risk operation starting tomorrow.'),

                        Select::make('incident_id')
                            ->label('The incident behind it')
                            ->options(fn (callable $get): array => static::incidents($get('job_id')))
                            ->searchable()
                            ->placeholder('None'),

                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    /** @return array<int, string> */
    private static function locations(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return Location::query()
            ->where('job_id', $jobId)
            ->orderByRaw('LENGTH(path)')
            ->orderBy('sort_order')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Location $location): array => [$location->getKey() => $location->fullName()])
            ->all();
    }

    /** @return array<int, string> */
    private static function incidents(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return Incident::query()
            ->where('job_id', $jobId)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (Incident $incident): array => [$incident->getKey() => $incident->displayName()])
            ->all();
    }
}
