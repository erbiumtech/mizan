<?php

namespace App\Modules\ConstructionField\Filament\Resources\Submittals\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\ProgrammeActivity;
use App\Modules\ConstructionField\Models\Submittal;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * What has to be approved, and when it has to go in.
 *
 * **The submit-by date is not a field on this form.** It is the required-on-site date less the four durations, and §16.3
 * refuses to store it because "typed, it goes stale the day the programme moves". What the form does instead is *show*
 * the date the figures produce, and the days remaining, while somebody is still typing them — which is the moment a
 * negative number can still be acted on.
 *
 * That placeholder is the single most useful thing on the screen. A planner entering a ninety-day fabrication against a
 * date six weeks away learns it here, rather than in the month the steel does not arrive.
 */
class SubmittalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What it is')
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
                            ->disabled(fn (?Submittal $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('contract_id')
                            ->label('Under contract')
                            ->options(fn (callable $get): array => static::contracts($get('job_id')))
                            ->searchable()
                            ->visible(fn (): bool => modules()->enabled('construction_contracts'))
                            ->placeholder('None'),

                        TextInput::make('spec_section')
                            ->label('Specification section')
                            ->required()
                            ->maxLength(255)
                            ->helperText('03 30 00, E20, "Section 7" — whatever this project uses. The register is organised by it.'),

                        Select::make('type')
                            ->options(Submittal::TYPES)
                            ->default('shop_drawing')
                            ->required()
                            ->searchable(),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Textarea::make('description')->rows(2)->columnSpanFull(),

                        Select::make('responsible_contact_id')
                            ->label('Who owes it')
                            ->relationship('responsible', 'name')
                            ->searchable()
                            ->preload()
                            // Contacts belong to Invoicing, which this module does not require.
                            ->visible(fn (): bool => modules()->enabled('invoicing')),

                        TextInput::make('responsible_label')
                            ->label('Who owes it, in words')
                            ->maxLength(255)
                            ->helperText('The subcontractor or supplier, where you keep no contact record for them.'),
                    ]),

                Section::make('The clock')
                    ->columns(2)
                    ->description('The submit-by date is computed from these, never typed — a typed one goes stale the day the programme moves, and a stale submit-by date is worse than none.')
                    ->schema([
                        DatePicker::make('required_on_site_date')
                            ->label('Needed on site')
                            ->native(false)
                            ->live()
                            ->helperText('From the programme. Everything below is subtracted from it.'),

                        TextInput::make('review_period_days')
                            ->label('Review period (days)')
                            ->numeric()
                            ->default(14)
                            ->live(onBlur: true)
                            ->helperText('The contract\'s. Each round snapshots whatever this is when it goes out.'),

                        TextInput::make('fabrication_lead_days')
                            ->label('Fabrication (days)')
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: true),

                        TextInput::make('procurement_lead_days')
                            ->label('Procurement (days)')
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: true),

                        TextInput::make('buffer_days')
                            ->label('Buffer (days)')
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: true)
                            ->helperText('The planner\'s cushion, kept separate so it can be seen and argued about.'),

                        /*
                         * **The answer, shown while it can still be acted on.**
                         *
                         * A ninety-day fabrication entered against a date six weeks away is a problem worth learning
                         * here rather than in the month the steel does not arrive.
                         */
                        Placeholder::make('submit_by')
                            ->label('Has to be submitted by')
                            ->columnSpanFull()
                            ->content(fn (callable $get): string => static::submitBy($get)),
                    ]),

                Section::make('Risk and paperwork')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_long_lead')
                            ->label('Long lead')
                            ->helperText('Flagged by hand: ninety days is long lead on a six-month job and ordinary on a four-year one. It is what filters four hundred items down to the twenty that will stop the job.'),

                        TextInput::make('revision')
                            ->maxLength(255)
                            ->helperText('The current one. Each round records the revision it reviewed.'),

                        /*
                         * **Which activity this submittal gates** — §16.3's `activity_id`, wired in Phase 9g.
                         *
                         * The join that makes a submittal register a schedule control rather than a filing cabinet: an
                         * approval outstanding against an activity that starts in three weeks is a different problem
                         * from one against an activity that starts next year.
                         */
                        Select::make('activity_id')
                            ->label('Gates activity')
                            ->options(fn (callable $get): array => static::activities($get('job_id')))
                            ->searchable()
                            ->columnSpanFull()
                            ->helperText('From the programme. What cannot start until this is approved.'),

                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The computed date, in words, from whatever is currently in the form.
     *
     * Built from the same arithmetic as `Submittal::submitBy()` rather than by instantiating a model, because the form
     * state is not a record yet — and duplicating four additions is cheaper than the confusion of a half-hydrated
     * model. The sentence says which figures produced it, so a surprising answer is traceable to the field that caused
     * it.
     */
    private static function submitBy(callable $get): string
    {
        $required = $get('required_on_site_date');

        if (blank($required)) {
            return 'Set a date it is needed on site and this fills in. Without one there is nothing to work backwards from.';
        }

        $lead = (int) $get('fabrication_lead_days')
            + (int) $get('procurement_lead_days')
            + (int) $get('review_period_days')
            + (int) $get('buffer_days');

        $by = \Carbon\Carbon::parse($required)->subDays($lead);
        $days = (int) now()->startOfDay()->diffInDays($by, absolute: false);

        $when = $days < 0
            ? abs($days).' days ago — this one is already late'
            : ($days === 0 ? 'today' : "in {$days} days");

        return $by->format('D d M Y')." ({$when}). ".$lead.' days of lead time subtracted.';
    }

    /**
     * The job's programme activities.
     *
     * A real query: §13 and §16 both live in `construction_field`, so the programme is not a separate purchase.
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

    /**
     * The job's contracts, read out of the table.
     *
     * `construction_contracts` is guarded — this module requires only `construction` — so the picker asks the query
     * builder and never names `Contract`.
     *
     * @return array<int, string>
     */
    private static function contracts(int|string|null $jobId): array
    {
        if ($jobId === null || ! modules()->enabled('construction_contracts')) {
            return [];
        }

        return DB::table('construction_contracts')
            ->where('job_id', $jobId)
            ->orderBy('contract_number')
            ->get(['id', 'contract_number', 'title'])
            ->mapWithKeys(fn ($row): array => [$row->id => "{$row->contract_number} — {$row->title}"])
            ->all();
    }
}
