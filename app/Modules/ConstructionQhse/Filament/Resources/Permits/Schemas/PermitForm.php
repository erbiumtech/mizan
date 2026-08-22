<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Permits\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\ConstructionQhse\Models\Permit;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Requesting a permit.
 *
 * **Both ends of the window are datetimes and both are required.** §17.5: "a permit is time-boxed, and an
 * expired-but-open permit is the failure mode that kills people." A permit valid "on the 20th" authorises hot work at
 * four in the morning, and this form will not produce one.
 *
 * **The type-specific fields live in a key-value bag**, which §17.5 asks for by name: gas readings, an isolation
 * certificate reference, a rescue plan, wind limits. The shared fields stay real columns so the register can answer
 * "what is open on level four right now" — a question a schema with everything in JSON cannot.
 *
 * The form lists the keys the chosen type's procedure turns on, because being told at issue that a confined-space permit
 * needs a rescue plan is worse than being told while writing it.
 */
class PermitForm
{
    /**
     * What each type's procedure asks for, mirrored from `PermitService::REQUIRED_DETAILS` for the *prompt*.
     *
     * Duplicated deliberately rather than exposed from the service: this is help text and that is a rule, and a form
     * reaching into a private constant to render a hint would couple the wording of one to the enforcement of the other.
     * The service is what refuses.
     *
     * @var array<string, string>
     */
    private const PROMPTS = [
        'hot_work' => 'This type needs fire_watch and extinguisher_present before it can be issued.',
        'confined_space' => 'This type needs rescue_plan and gas_test before it can be issued.',
        'excavation' => 'This type needs services_scanned before it can be issued.',
        'electrical_isolation' => 'This type needs isolation_certificate before it can be issued.',
        'lifting_operation' => 'This type needs lift_plan before it can be issued.',
        'live_services' => 'This type needs isolation_certificate before it can be issued.',
        'radiography' => 'This type needs exclusion_zone before it can be issued.',
        'diving' => 'This type needs rescue_plan before it can be issued.',
        'pressure_testing' => 'This type needs exclusion_zone before it can be issued.',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The work')
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
                            ->disabled(fn (?Permit $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('type')
                            ->options(Permit::TYPES)
                            ->required()
                            ->live()
                            ->searchable(),

                        Textarea::make('description')
                            ->label('What is being done')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Specific enough that the person doing the work can check themselves against it.'),

                        Select::make('location_id')
                            ->label('Where')
                            ->options(fn (callable $get): array => static::locations($get('job_id')))
                            ->searchable(),

                        TextInput::make('location_detail')->label('Where exactly')->maxLength(255),

                        Textarea::make('activity')->label('Method, in short')->rows(2)->columnSpanFull(),

                        TextInput::make('requester_label')
                            ->label('Requested by')
                            ->maxLength(255)
                            ->helperText('A name is enough — usually the supervisor of the gang doing the work.'),

                        TextInput::make('persons_count')->label('How many people')->numeric(),
                    ]),

                Section::make('The window')
                    ->columns(2)
                    ->description('Both ends, with times. A permit valid "on the 20th" authorises hot work at four in the morning — which is why this form will not accept a date on its own.')
                    ->schema([
                        DateTimePicker::make('valid_from')
                            ->label('Valid from')
                            ->seconds(false)
                            ->required()
                            ->live(onBlur: true),

                        DateTimePicker::make('valid_to')
                            ->label('Valid to')
                            ->seconds(false)
                            ->required()
                            ->live(onBlur: true),

                        Placeholder::make('window')
                            ->label('')
                            ->columnSpanFull()
                            ->visible(fn (callable $get): bool => filled($get('valid_from')) && filled($get('valid_to')))
                            ->content(function (callable $get): string {
                                $from = \Carbon\Carbon::parse($get('valid_from'));
                                $to = \Carbon\Carbon::parse($get('valid_to'));

                                if ($to->lte($from)) {
                                    return 'That window ends before it starts.';
                                }

                                $hours = (int) $from->diffInHours($to, absolute: false);

                                return "Authorises {$hours} hour(s) of work"
                                    .($to->isPast()
                                        ? '. That window has already closed — a permit cannot be issued for work that is already over.'
                                        : ', ending '.$to->format('D d M \a\t H:i').'.');
                            }),
                    ]),

                Section::make('The controls this type turns on')
                    ->columns(1)
                    ->schema([
                        Placeholder::make('required_details')
                            ->label('')
                            ->visible(fn (callable $get): bool => isset(self::PROMPTS[$get('type') ?? '']))
                            ->content(fn (callable $get): string => self::PROMPTS[$get('type')] ?? ''),

                        /*
                         * §17.5's JSON bag: "the genuinely type-specific fields (gas readings, isolation certificate
                         * reference, rescue plan, wind limits)". Free-form on purpose — thirteen types' fields as columns
                         * would be ninety mostly-null columns.
                         */
                        KeyValue::make('details')
                            ->label('Type-specific controls')
                            ->keyLabel('Control')
                            ->valueLabel('Detail')
                            ->addActionLabel('Add a control')
                            ->helperText('Gas readings, an isolation certificate reference, a rescue plan, wind limits. What matters is that the ones this type turns on are here before it is issued.'),
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
