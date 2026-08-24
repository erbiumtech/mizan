<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\Ncr;
use App\Support\TenantDb;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Raising and working an NCR.
 *
 * **The disposition is not on this form and neither is the deduction.** Both are actions with rules attached: a
 * disposition is §17.2's "field that decides whether money changes hands", and a deduction is a *proposal* that a person
 * on the certification side has to take up and sign for. A form that could set either would settle a commercial question
 * as a side effect of typing.
 *
 * **CAPA is two sections, not one.** ISO 9001:2015 dropped preventive action as a clause and every construction client's
 * quality manual still demands both; §17.2's complaint is that merging them "produces NCRs whose *preventive action*
 * restates the fix". Two headings, two owners, two due dates — because the first is about this pour and the second is
 * about the next forty.
 */
class NcrForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The non-conformance')
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
                            ->disabled(fn (?Ncr $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('contract_id')
                            ->label('Under contract')
                            ->options(fn (callable $get): array => static::contracts($get('job_id')))
                            ->searchable()
                            ->visible(fn (): bool => modules()->enabled('construction_contracts'))
                            ->placeholder('None'),

                        Select::make('severity')
                            ->options(Ncr::SEVERITIES)
                            ->default(Ncr::SEVERITY_MINOR)
                            ->required()
                            ->helperText('Critical means structural adequacy, safety or a statutory requirement — not "expensive". Deriving it from cost would make a cheap structural defect look minor.'),

                        Select::make('source')
                            ->options([
                                'inspection' => 'Inspection',
                                'audit' => 'Audit',
                                'client' => 'Client',
                                'self_identified' => 'Found ourselves',
                                'testing' => 'Testing',
                                'supplier' => 'Supplier',
                                'other' => 'Other',
                            ])
                            ->default('inspection')
                            ->required(),

                        Textarea::make('description')
                            ->label('What is wrong')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),

                        Textarea::make('requirement_breached')
                            ->label('The requirement it breaches')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('"The cover is 22 mm" is the description; "BS EN 1992 clause 4.4.1, 40 mm minimum" is this. An NCR with only the first is an opinion.'),

                        TextInput::make('category')
                            ->maxLength(255)
                            ->helperText('Workmanship, materials, documentation, dimensional.'),

                        DatePicker::make('raised_on')->native(false)->default(now())->required(),
                    ]),

                Section::make('Where, and who')
                    ->columns(2)
                    ->schema([
                        Select::make('inspection_id')
                            ->label('Found by inspection')
                            ->options(fn (callable $get): array => static::inspections($get('job_id')))
                            ->searchable()
                            ->helperText('Choosing one carries the ITP row across, which is the traceability the standard asks for.'),

                        Select::make('itp_activity_id')
                            ->label('ITP point')
                            ->options(fn (callable $get): array => static::itpPoints($get('job_id')))
                            ->searchable()
                            ->helperText('The plan row this failed against.'),

                        Select::make('location_id')
                            ->label('Where')
                            ->options(fn (callable $get): array => static::locations($get('job_id')))
                            ->searchable(),

                        Select::make('wbs_node_id')
                            ->label('WBS node')
                            ->options(fn (callable $get): array => static::wbsNodes($get('job_id')))
                            ->searchable(),

                        Select::make('responsible_contact_id')
                            ->label('Who has to put it right')
                            ->relationship('responsible', 'name')
                            ->searchable()
                            ->preload()
                            ->visible(fn (): bool => modules()->enabled('invoicing')),

                        TextInput::make('responsible_label')
                            ->label('Who, in words')
                            ->maxLength(255),

                        TextInput::make('cost_impact')
                            ->label('Expected cost to put right')
                            ->numeric()
                            ->helperText('An estimate. It is not a deduction — proposing one is a separate act, and applying it happens on a certificate that somebody signs.'),
                    ]),

                Section::make('Root cause and corrective action')
                    ->columns(2)
                    ->description('About this occurrence: what happened, why, and what is being done to the work in front of you.')
                    ->schema([
                        Textarea::make('root_cause')->rows(2)->columnSpanFull(),

                        TextInput::make('root_cause_method')
                            ->label('How the cause was found')
                            ->maxLength(255)
                            ->helperText('Five whys, fishbone, 8D. Recorded because an audit asks, and because "we discussed it" is not a method.'),

                        Textarea::make('corrective_action')->rows(2)->columnSpanFull(),
                        TextInput::make('corrective_owner_label')->label('Owner')->maxLength(255),
                        DatePicker::make('corrective_due_on')->label('Due')->native(false),
                        DatePicker::make('corrective_done_on')->label('Done')->native(false),
                    ]),

                Section::make('Preventive action')
                    ->columns(2)
                    ->description('About the next forty. ISO 9001:2015 dropped preventive action as a clause and every client\'s quality manual still demands it — and an NCR whose preventive action restates the fix is the failure this section is kept separate to avoid.')
                    ->schema([
                        Textarea::make('preventive_action')->rows(2)->columnSpanFull(),
                        TextInput::make('preventive_owner_label')->label('Owner')->maxLength(255),
                        DatePicker::make('preventive_due_on')->label('Due')->native(false),
                        DatePicker::make('preventive_done_on')->label('Done')->native(false),

                        Placeholder::make('capa_note')
                            ->label('')
                            ->columnSpanFull()
                            ->visible(fn (?Ncr $record): bool => $record?->fixedButNotPrevented() ?? false)
                            ->content('The corrective action is done and this is not. That is the commonest CAPA failure: the pour gets fixed and the reason it happened does not.'),

                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    /** @return array<int, string> */
    private static function inspections(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return Inspection::query()
            ->where('job_id', $jobId)
            ->orderByDesc('inspected_on')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (Inspection $i): array => [$i->getKey() => $i->displayName()])
            ->all();
    }

    /** @return array<int, string> */
    private static function itpPoints(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return \App\Modules\ConstructionQhse\Models\ItpActivity::query()
            ->whereIn('itp_id', \App\Modules\ConstructionQhse\Models\Itp::query()->where('job_id', $jobId)->select('id'))
            ->with('itp')
            ->orderBy('itp_id')->orderBy('sequence')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn ($activity): array => [
                $activity->getKey() => $activity->itp?->reference.' '.$activity->sequence
                    .' — '.str($activity->activity_description)->limit(40),
            ])
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

    /** @return array<int, string> */
    private static function wbsNodes(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return WbsNode::query()
            ->where('job_id', $jobId)
            ->orderBy('code')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => "{$node->code} — {$node->name}"])
            ->all();
    }

    /**
     * The job's contracts, read out of the table — `construction_contracts` is guarded here.
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
}
