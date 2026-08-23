<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Inspections\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Support\TenantDb;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Requesting an inspection.
 *
 * **Neither the result nor the release is on this form**, and both absences are §17.1's. Recording what was found is an
 * action with a rule attached; releasing a hold point is a *third* act with its own permission, because passing an
 * inspection and authorising the next operation to start are two decisions and on a certified site they are two people.
 *
 * The point type is chosen only for an ad-hoc inspection. Where a plan row is picked, it is **snapshotted from the plan**
 * — an ITP gets revised and a hold point becomes a witness point, and an inspection carried out under the old plan was
 * carried out under the old rules.
 */
class InspectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What is being inspected')
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
                            ->disabled(fn (?Inspection $record): bool => $record !== null)
                            ->dehydrated(),

                        /*
                         * The plan row, and choosing one is what makes the inspection traceable to the document a
                         * certification body reads. Leaving it blank is an ad-hoc inspection, which §17.1 requires to be
                         * possible: a client's representative asking to see a detail is an inspection.
                         */
                        Select::make('itp_activity_id')
                            ->label('ITP point')
                            ->options(fn (callable $get): array => static::itpPoints($get('job_id')))
                            ->searchable()
                            ->live()
                            ->placeholder('None — an ad-hoc inspection')
                            ->helperText('Choosing a point snapshots its type and notice period onto this inspection. Leave it blank for an inspection nobody planned.'),

                        Textarea::make('activity_description')
                            ->label('What is being inspected')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull(),

                        Select::make('location_id')
                            ->label('Where')
                            ->options(fn (callable $get): array => static::locations($get('job_id')))
                            ->searchable(),

                        Select::make('contract_item_id')
                            ->label('Contract item')
                            ->options(fn (callable $get): array => static::contractItems($get('job_id')))
                            ->searchable()
                            ->visible(fn (): bool => modules()->enabled('construction_contracts')),

                        /*
                         * Only offered where there is no plan row: with one, the type comes from the plan and typing it
                         * would let somebody downgrade a hold point to a review on the way past.
                         */
                        Select::make('point_type')
                            ->label('Point type')
                            ->options(ItpActivity::POINT_TYPES)
                            ->default(ItpActivity::POINT_REVIEW)
                            ->required()
                            ->visible(fn (callable $get): bool => blank($get('itp_activity_id')))
                            ->helperText('A hold point stops work until it is released. Choose it deliberately.'),

                        TextInput::make('notice_hours')
                            ->label('Notice required (hours)')
                            ->numeric()
                            ->visible(fn (callable $get): bool => blank($get('itp_activity_id'))),

                        Placeholder::make('from_plan')
                            ->label('')
                            ->columnSpanFull()
                            ->visible(fn (callable $get): bool => filled($get('itp_activity_id')))
                            ->content('The point type and notice period come from the plan and are frozen onto this inspection when it is requested — a later revision of the ITP cannot change what this inspection meant.'),
                    ]),

                Section::make('Notice and attendance')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('requested_on')
                            ->label('Requested on')
                            ->native(false)
                            ->default(now())
                            ->required(),

                        DatePicker::make('scheduled_for')->label('Scheduled for')->native(false),

                        Select::make('witness_contact_id')
                            ->label('Witness')
                            ->relationship('witness', 'name')
                            ->searchable()
                            ->preload()
                            ->visible(fn (): bool => modules()->enabled('invoicing')),

                        TextInput::make('witness_label')
                            ->label('Witness, in words')
                            ->maxLength(255)
                            ->helperText('The attending party, where you keep no contact record for them.'),
                    ]),
            ]);
    }

    /**
     * The job's ITP points, from plans that are actually in force.
     *
     * A draft plan's points are deliberately absent: requesting against one would cite a document nobody has published,
     * and the service refuses it anyway — offering it here would make that refusal look like a bug.
     *
     * @return array<int, string>
     */
    private static function itpPoints(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return ItpActivity::query()
            ->active()
            ->whereIn('itp_id', Itp::query()->where('job_id', $jobId)->inForce()->select('id'))
            ->with('itp')
            ->orderBy('itp_id')
            ->orderBy('sequence')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (ItpActivity $activity): array => [
                $activity->getKey() => $activity->itp?->reference.' '.$activity->sequence
                    .' — '.str($activity->activity_description)->limit(50)
                    .' ('.match ($activity->point_type) {
                        ItpActivity::POINT_HOLD => 'hold',
                        ItpActivity::POINT_WITNESS => 'witness',
                        default => $activity->point_type,
                    }.')',
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

    /**
     * The contract's priced lines, read out of the table.
     *
     * `construction_contracts` is guarded — this module requires only `construction` — so the picker asks the query
     * builder and never names `ContractItem`.
     *
     * @return array<int, string>
     */
    private static function contractItems(int|string|null $jobId): array
    {
        if ($jobId === null || ! modules()->enabled('construction_contracts')) {
            return [];
        }

        $contracts = TenantDb::table('construction_contracts')->where('job_id', $jobId)->pluck('id');

        if ($contracts->isEmpty()) {
            return [];
        }

        return TenantDb::table('construction_contract_items')
            ->whereIn('contract_id', $contracts)
            ->where('is_active', true)
            ->orderBy('sort')->orderBy('item_no')
            ->limit(500)
            ->get(['id', 'item_no', 'description'])
            ->mapWithKeys(fn ($row): array => [$row->id => $row->item_no.' — '.str($row->description)->limit(60)])
            ->all();
    }
}
