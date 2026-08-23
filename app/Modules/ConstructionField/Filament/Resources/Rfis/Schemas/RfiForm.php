<?php

namespace App\Modules\ConstructionField\Filament\Resources\Rfis\Schemas;

use App\Modules\Construction\Models\Document;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\ConstructionField\Models\ProgrammeActivity;
use App\Modules\ConstructionField\Models\Rfi;
use App\Support\TenantDb;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Asking the question — §16.2.
 *
 * **The proposed solution is on this form and it is not decoration.** A question with a proposal attached is a
 * yes-or-no; a question without one is homework for somebody who did not ask for it, and the difference shows up in the
 * response time the register measures.
 *
 * **The impact fields are flags, and the estimates only appear once a flag says there is something to estimate.** §16.2
 * is written against the alternative: "at raise time nobody knows, and forcing a number produces a column of zeros that
 * later reads as *no impact* when it meant *not yet assessed*."
 *
 * The RFI number is not on the form. It is assigned per job in an unbroken series, and a typed one is how two RFIs come
 * to share a number in a register both parties quote from.
 */
class RfiForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The question')
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
                            ->disabled(fn (?Rfi $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('contract_id')
                            ->label('Under contract')
                            ->options(fn (callable $get): array => static::contracts($get('job_id')))
                            ->searchable()
                            ->visible(fn (): bool => modules()->enabled('construction_contracts'))
                            ->placeholder('None'),

                        TextInput::make('subject')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('What appears in the register and in the chasing email. Make it recognisable in six months.'),

                        Textarea::make('question')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),

                        Textarea::make('proposed_solution')
                            ->label('What we propose')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('A question with a proposal is a yes-or-no. A question without one is homework for somebody who did not ask for it — and it comes back slower.'),
                    ]),

                Section::make('What it is about')
                    ->columns(2)
                    ->schema([
                        Select::make('drawing_document_id')
                            ->label('Drawing')
                            ->options(fn (callable $get): array => static::drawings($get('job_id')))
                            ->searchable()
                            ->helperText('From the document register, so the answer can be read against the revision that prompted it.'),

                        TextInput::make('specification_reference')
                            ->label('Specification reference')
                            ->maxLength(255),

                        Select::make('location_id')
                            ->label('Where')
                            ->options(fn (callable $get): array => static::locations($get('job_id')))
                            ->searchable(),

                        TextInput::make('discipline')
                            ->maxLength(255)
                            ->helperText('Architectural, structural, mechanical — whatever this project calls them.'),

                        /*
                         * **Which programme activity this question blocks** — §16.2's `activity_id`, wired in Phase 9g.
                         *
                         * Left out of 9d on purpose: the programme did not exist, and a nullable integer nothing could
                         * populate reads like an unfinished feature. It is what turns "seventeen RFIs outstanding" into
                         * "seventeen RFIs outstanding, four of them against activities that should have started".
                         */
                        Select::make('activity_id')
                            ->label('Blocks activity')
                            ->options(fn (callable $get): array => static::activities($get('job_id')))
                            ->searchable()
                            ->columnSpanFull()
                            ->helperText('From the programme. An RFI against a dated activity is assessable; one against nothing is a complaint.'),
                    ]),

                Section::make('Who owes the answer, and by when')
                    ->columns(2)
                    ->schema([
                        /*
                         * **The role and the person, both** — §16.2's "both, deliberately".
                         *
                         * The role is what the register counts; the contact is who the email goes to. The individual
                         * changes three times over a two-year job and the role does not.
                         */
                        Select::make('ball_in_court')
                            ->label('Ball in court')
                            ->options(Rfi::COURTS)
                            ->default('architect')
                            ->required()
                            ->helperText('The role, which is what "seventeen RFIs with the Architect" counts.'),

                        Select::make('ball_in_court_contact_id')
                            ->label('With whom')
                            ->relationship('ballInCourtContact', 'name')
                            ->searchable()
                            ->preload()
                            // Contacts belong to Invoicing, which this module does not require. Without it the role
                            // carries the register on its own and only the chasing email loses its address.
                            ->visible(fn (): bool => modules()->enabled('invoicing'))
                            ->helperText('The named individual, which is who a chase goes to.'),

                        DatePicker::make('raised_on')
                            ->label('Raised on')
                            ->native(false)
                            ->default(now())
                            ->required(),

                        DatePicker::make('required_by')
                            ->label('Answer needed by')
                            ->native(false)
                            ->helperText('What makes this register a clock rather than a list. An RFI with no date is one nobody is chasing.'),
                    ]),

                Section::make('Impact')
                    ->columns(2)
                    ->description('Flags now, figures when somebody knows. A number typed at raise time becomes a column of zeros that later reads as "no impact" when it meant "not yet assessed".')
                    ->schema([
                        Select::make('cost_impact_flag')
                            ->label('Cost impact')
                            ->options(Rfi::IMPACTS)
                            ->default(Rfi::IMPACT_NONE)
                            ->required()
                            ->live(),

                        TextInput::make('cost_impact_estimate')
                            ->label('Estimated cost')
                            ->numeric()
                            ->visible(fn (callable $get): bool => $get('cost_impact_flag') !== Rfi::IMPACT_NONE)
                            ->helperText('Leave blank until there is a figure.'),

                        Select::make('time_impact_flag')
                            ->label('Time impact')
                            ->options(Rfi::IMPACTS)
                            ->default(Rfi::IMPACT_NONE)
                            ->required()
                            ->live(),

                        TextInput::make('time_impact_days')
                            ->label('Estimated days')
                            ->numeric()
                            ->visible(fn (callable $get): bool => $get('time_impact_flag') !== Rfi::IMPACT_NONE),

                        /*
                         * Said at the moment somebody sets the flag, which is the moment it can still be acted on.
                         *
                         * A notice period is already running from the day the answer was needed; the register's action
                         * raises the event at that date rather than today.
                         */
                        Placeholder::make('delay_warning')
                            ->label('')
                            ->columnSpanFull()
                            ->visible(fn (callable $get, ?Rfi $record): bool => $get('time_impact_flag') === Rfi::IMPACT_YES
                                && $record?->delay_event_id === null)
                            ->content('A stated time impact needs a delay event behind it, or the notice period runs out in silence. Use **Raise delay event** on the register — it dates the notice from the day the answer was needed, not today.'),
                    ]),
            ]);
    }

    /**
     * The job's contracts, read out of the table.
     *
     * `construction_contracts` is guarded here — this module requires only `construction` — so the picker asks the
     * query builder and never names `Contract`, exactly as §13's delay event does.
     *
     * @return array<int, string>
     */
    private static function contracts(int|string|null $jobId): array
    {
        if ($jobId === null || ! modules()->enabled('construction_contracts')) {
            return [];
        }

        return TenantDb::table('construction_contracts')
            ->where('job_id', $jobId)
            ->orderBy('contract_number')
            ->get(['id', 'contract_number', 'title'])
            ->mapWithKeys(fn ($row): array => [$row->id => "{$row->contract_number} — {$row->title}"])
            ->all();
    }

    /** @return array<int, string> */
    private static function drawings(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return Document::query()
            ->where('job_id', $jobId)
            ->whereIn('document_type', ['drawing', 'specification', 'schedule'])
            ->orderBy('information_container_id')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Document $document): array => [
                $document->getKey() => "{$document->information_container_id} — {$document->title}",
            ])
            ->all();
    }

    /**
     * The job's programme activities.
     *
     * `ProgrammeActivity` is this module's own — §13 and §16 are both `construction_field` — so this is a real query rather than
     * a query-builder read. The programme is not a separate purchase.
     *
     * @return array<int, string>
     */
    private static function activities(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return ProgrammeActivity::query()
            ->where('job_id', $jobId)
            ->orderBy('code')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (ProgrammeActivity $activity): array => [$activity->getKey() => $activity->displayName()])
            ->all();
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
