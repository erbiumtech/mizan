<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Incidents\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\ConstructionQhse\Models\Incident;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Reporting something that happened, or nearly did.
 *
 * **The first section is short on purpose.** §17.3's leading indicator is near misses per lost-time injury, and a form
 * that asked twenty questions before it would accept a near miss would collect very few of them. Kind, when, where, what
 * — and everything about injury, investigation and authorities is a later section that stays empty on most rows.
 *
 * **The time is a datetime and it is required.** Shift timing is half the analysis, and a date column loses hour ten of
 * a twelve-hour shift, the first hour back from a break, and the last night of a run of nights.
 *
 * **The reporting delay is shown while typing**, because that figure is itself a safety metric and the moment it can be
 * acted on is now rather than in next month's report.
 */
class IncidentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What happened')
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
                            ->disabled(fn (?Incident $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('kind')
                            ->options(Incident::KINDS)
                            ->default(Incident::KIND_NEAR_MISS)
                            ->required()
                            ->live()
                            ->helperText('Near miss is first for a reason: near misses per lost-time injury is the number that predicts the next injury.'),

                        DateTimePicker::make('occurred_at')
                            ->label('When it happened')
                            ->seconds(false)
                            ->required()
                            ->live(onBlur: true)
                            ->maxDate(now())
                            ->helperText('The time, not just the day. Hour ten of a twelve-hour shift is a finding.'),

                        DateTimePicker::make('reported_at')
                            ->label('When it was reported')
                            ->seconds(false)
                            ->default(now())
                            ->live(onBlur: true)
                            ->helperText('Kept separately, because the delay between the two is a safety measure in its own right.'),

                        Placeholder::make('reporting_delay')
                            ->label('')
                            ->columnSpanFull()
                            ->visible(fn (callable $get): bool => filled($get('occurred_at')) && filled($get('reported_at')))
                            ->content(function (callable $get): string {
                                $hours = \Carbon\Carbon::parse($get('occurred_at'))
                                    ->diffInHours(\Carbon\Carbon::parse($get('reported_at')), absolute: false);
                                $limit = (int) config('construction.qhse.report_within_hours', 24);

                                return $hours < 0
                                    ? 'That is a report before the event — check the dates.'
                                    : "Reported {$hours} hour(s) after it happened"
                                        .($hours > $limit
                                            ? ", which is beyond this company's {$limit}-hour policy. The delay is itself a measure of whether things get reported at all."
                                            : '.');
                            }),

                        Textarea::make('description')
                            ->label('What happened')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),

                        Select::make('location_id')
                            ->label('Where')
                            ->options(fn (callable $get): array => static::locations($get('job_id')))
                            ->searchable(),

                        TextInput::make('location_detail')
                            ->label('Where exactly')
                            ->maxLength(255),

                        Textarea::make('activity_being_performed')
                            ->label('What was being done at the time')
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('immediate_action')
                            ->label('What was done straight away')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Who was hurt')
                    ->columns(2)
                    ->description('Empty on a near miss, an unsafe act or property damage — which is most of what belongs in this register.')
                    ->schema([
                        Select::make('injured_person_type')
                            ->label('Who')
                            ->options([
                                'nobody' => 'Nobody was hurt',
                                'employee' => 'Our employee',
                                'subcontractor' => 'A subcontractor\'s',
                                'visitor' => 'A visitor',
                                'public' => 'A member of the public',
                                'other' => 'Other',
                            ])
                            ->default('nobody')
                            ->required()
                            ->live(),

                        TextInput::make('injured_person_name')
                            ->label('Name')
                            ->maxLength(255)
                            ->visible(fn (callable $get): bool => $get('injured_person_type') !== 'nobody')
                            ->helperText('A name typed in is enough and is usually all there is — most people on most sites are somebody else\'s employees.'),

                        TextInput::make('injured_person_employer')
                            ->label('Employer')
                            ->maxLength(255)
                            ->visible(fn (callable $get): bool => $get('injured_person_type') !== 'nobody'),

                        TextInput::make('injured_person_age')
                            ->numeric()
                            ->visible(fn (callable $get): bool => $get('injured_person_type') !== 'nobody'),

                        Toggle::make('is_lost_time')
                            ->label('Time was lost')
                            ->visible(fn (callable $get): bool => $get('injured_person_type') !== 'nobody')
                            ->helperText('Set for a lost-time injury or fatality automatically. Kept separate from the day count, because a case where nobody yet knows how long somebody is off is the ordinary state for a fortnight.'),

                        TextInput::make('days_lost')->numeric()
                            ->visible(fn (callable $get): bool => $get('injured_person_type') !== 'nobody'),

                        TextInput::make('restricted_days')->numeric()
                            ->visible(fn (callable $get): bool => $get('injured_person_type') !== 'nobody'),

                        TextInput::make('treatment')->maxLength(255)
                            ->visible(fn (callable $get): bool => $get('injured_person_type') !== 'nobody')
                            ->helperText('First aid on site, hospital, GP, none.'),

                        TextInput::make('body_part')->maxLength(255)
                            ->visible(fn (callable $get): bool => $get('injured_person_type') !== 'nobody'),

                        TextInput::make('injury_type')->maxLength(255)
                            ->visible(fn (callable $get): bool => $get('injured_person_type') !== 'nobody')
                            ->helperText('Laceration, fracture, sprain, burn.'),

                        TextInput::make('agency')
                            ->label('What did the harm')
                            ->maxLength(255)
                            ->visible(fn (callable $get): bool => $get('injured_person_type') !== 'nobody')
                            ->helperText('The machine, the surface, the substance.'),
                    ]),

                Section::make('Cause, and the authority')
                    ->columns(2)
                    ->schema([
                        Textarea::make('immediate_cause')->rows(2)->columnSpanFull(),
                        Textarea::make('root_cause')->rows(2)->columnSpanFull(),
                        TextInput::make('root_cause_method')
                            ->label('How the cause was found')
                            ->maxLength(255),
                        DatePicker::make('investigation_completed_on')->native(false),

                        Toggle::make('reportable_to_authority')
                            ->label('Reportable to an authority')
                            ->live()
                            ->helperText('Mark it as soon as it is assessed. An unreported reportable incident is the one exposure in this module with a statutory clock on it, and the register counts them on the sidebar.'),

                        TextInput::make('authority_name')
                            ->maxLength(255)
                            ->visible(fn (callable $get): bool => (bool) $get('reportable_to_authority'))
                            ->helperText('HSE, OSHA, the labour department.'),

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
}
