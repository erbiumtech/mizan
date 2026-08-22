<?php

namespace App\Modules\Construction\Filament\Resources\Jobs\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Invoicing\Models\Contact;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What a job is, in the order somebody fills it in.
 *
 * The commercial terms are their own section and deliberately not required: a job exists at tender stage
 * before any of them are agreed, and a form that demands a retention percentage to save a tender is a form
 * people work around. §1's status enum starts at `tender` for that reason.
 */
class JobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The job')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Job number')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('The number everyone on site will quote — J-2026-014.'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Select::make('parent_id')
                            ->label('Part of')
                            ->relationship('parent', 'name')
                            ->searchable()
                            ->preload()
                            // A job cannot be its own parent, and the tree is what every rollup reads.
                            ->helperText('For a lot, tower or call-off certified separately from its parent.'),

                        Select::make('nature')
                            ->options([
                                'building' => 'Building',
                                'civils' => 'Civils',
                                'infrastructure' => 'Infrastructure',
                                'fit_out' => 'Fit-out',
                                'mep' => 'MEP',
                                'marine' => 'Marine',
                                'other' => 'Other',
                            ])
                            ->default('building')
                            ->selectablePlaceholder(false)
                            ->native(false),

                        Select::make('status')
                            ->options([
                                'tender' => 'Tender',
                                'awarded' => 'Awarded',
                                'mobilising' => 'Mobilising',
                                'in_progress' => 'In progress',
                                'suspended' => 'Suspended',
                                'substantial_completion' => 'Substantial completion',
                                'defects_liability' => 'Defects liability',
                                'final_account' => 'Final account',
                                'closed' => 'Closed',
                                'cancelled' => 'Cancelled',
                                'lost' => 'Lost',
                            ])
                            ->default(Job::STATUS_TENDER)
                            ->selectablePlaceholder(false)
                            ->native(false),

                        Textarea::make('description')
                            ->columnSpanFull()
                            ->rows(2),
                    ]),

                Section::make('The contract')
                    ->description('Which contract family this job follows, and who certifies under it.')
                    ->columns(2)
                    ->schema([
                        Select::make('contract_standard')
                            ->label('Contract standard')
                            ->options([
                                Job::STANDARD_FIDIC => 'FIDIC',
                                Job::STANDARD_AIA => 'AIA',
                                Job::STANDARD_CUSTOM => 'Custom',
                            ])
                            ->default(Job::STANDARD_FIDIC)
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->helperText('Drives the vocabulary and the certificate forms, not the tables.'),

                        // Both parties are Contacts, which are Invoicing's — and `construction` requires
                        // nothing (§18), so these are guarded rather than assumed. Without Invoicing there
                        // is no contact book to pick from, the fields are absent, the columns stay null and
                        // the job still exists: a tender has no client record either way. Same shape as
                        // `invoices.project_id`. The coupling is recorded in ModuleBoundaryTest.
                        Select::make('client_contact_id')
                            ->label('Client')
                            ->visible(fn (): bool => modules()->enabled('invoicing'))
                            ->options(fn (): array => Contact::query()
                                ->whereIn('kind', [Contact::KIND_CUSTOMER, Contact::KIND_BOTH])
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->helperText('Left blank until award.'),

                        /*
                         * The job's site store — §6, built in construction Phase 8a.
                         *
                         * Guarded like the two contact pickers above and for the same reason: `construction` requires
                         * nothing, so a contractor who tracks no stock never sees this and the column stays null. §6
                         * calls direct-to-site "the default" and says it "works with Inventory unlicensed", which is
                         * most contractors most of the time — the store is the exception, not the norm.
                         */
                        Select::make('stock_location_id')
                            ->label('Site store')
                            ->visible(fn (): bool => modules()->enabled('inventory'))
                            ->options(fn (): array => StockLocation::query()->active()->orderBy('code')->get()
                                ->mapWithKeys(fn (StockLocation $location): array => [
                                    $location->getKey() => $location->displayName(),
                                ])
                                ->all())
                            ->searchable()
                            ->placeholder('No store — everything direct to the work face')
                            ->helperText('Only needed if this job keeps material in a store. Without one, a delivery marked for a store is refused rather than mis-costed.'),

                        /*
                         * **Where the job's safety exposure hours come from** — §17.6, and the answer must be one place.
                         *
                         * §17.6 names two failures and this field is the second: "double counting the same people from
                         * the diary *and* from Timesheets, which halves every rate. The job names one source and the
                         * report prints which one it used." A halved frequency rate is worse than a missing one, because
                         * it is a number somebody can act on.
                         *
                         * Visible only where there is a choice to make: with neither module licensed there is no source
                         * and the safety page's refusal is the honest answer. Left blank on purpose where nobody has
                         * decided — a default would have quietly chosen for every job in the tenant, and "nobody has
                         * chosen" is a state §17.6's report names rather than papering over.
                         */
                        Select::make('exposure_hours_source')
                            ->label('Safety exposure hours from')
                            ->visible(fn (): bool => modules()->enabled('construction_qhse')
                                && (modules()->enabled('construction_field') || modules()->enabled('timesheets')))
                            ->options(fn (): array => array_filter([
                                'daily_log' => modules()->enabled('construction_field')
                                    ? 'The site diary — approved manpower returns' : null,
                                'timesheets' => modules()->enabled('timesheets')
                                    ? 'Timesheets — hours booked to the job' : null,
                            ]))
                            ->placeholder('Not chosen — safety rates will not be computed')
                            ->helperText('One source only. Counting both halves every safety frequency rate, and the indicator report prints which one it used.'),

                        Select::make('certifier_contact_id')
                            ->label('Certifier')
                            ->visible(fn (): bool => modules()->enabled('invoicing'))
                            ->options(fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->helperText('The Engineer under FIDIC, the Architect under AIA.'),

                        TextInput::make('contract_sum')
                            ->label('Contract sum (original)')
                            ->numeric()
                            ->helperText('The original sum. The revised sum is computed from approved variations and never typed.'),
                    ]),

                Section::make('The site')
                    ->columns(2)
                    ->schema([
                        TextInput::make('site_address_line_1')->label('Address')->maxLength(255),
                        TextInput::make('site_address_line_2')->label('Address line 2')->maxLength(255),
                        TextInput::make('site_city')->label('City')->maxLength(255),
                        TextInput::make('site_country')->label('Country')->maxLength(255),
                    ]),

                Section::make('Programme')
                    ->description('The completion date in force is the revised one, and it moves only through an approved extension of time.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('commencement_date')->native(false),
                        DatePicker::make('planned_completion_date')->native(false),
                        DatePicker::make('revised_completion_date')
                            ->native(false)
                            ->helperText('Set by an approved extension of time, not by hand.'),
                        TextInput::make('defects_period_days')
                            ->label('Defects liability (days)')
                            ->numeric()
                            ->minValue(0),
                    ]),
            ]);
    }
}
